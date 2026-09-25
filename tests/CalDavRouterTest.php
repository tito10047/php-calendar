<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\Server\CalDavRouter;
use Tito10047\Calendar\Server\CalendarCollectionInterface;
use Tito10047\Calendar\Server\CalendarEventStoreInterface;
use Tito10047\Calendar\Server\CalendarHomeInterface;

/**
 * K4 and K5 — discovery, more than one collection, and the change tag.
 *
 * A client is handed one address and has to find the rest by itself: who am I,
 * where are my calendars, which ones are there. Apple Calendar and DAVx5 do not
 * finish setting up an account any other way.
 */
final class CalDavRouterTest extends TestCase
{
    use DavXmlHelpers;

    private CalDavRouter $router;

    protected function setUp(): void
    {
        $this->router = new CalDavRouter(new InMemoryCalendarHome('jana', 'Jana', [
            new InMemoryCollection('walk', 'Ranná prechádzka', 'ctag-1', '#ffcc00'),
            new InMemoryCollection('reading', 'Čítanie', 'ctag-2', null, writable: false),
        ]), '/caldav/');
    }

    // -------------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------------

    public function testTheRootSaysWhoTheUserIs(): void
    {
        $response = $this->router->handle('PROPFIND', '/caldav/', $this->propfind(['D:current-user-principal']));

        self::assertSame(207, $response->statusCode);
        self::assertSame(
            '/caldav/principals/jana/',
            $this->nodeText($this->xpath($response->body), '//D:current-user-principal/D:href'),
        );
    }

    public function testThePrincipalSaysWhereTheCalendarsAre(): void
    {
        $response = $this->router->handle(
            'PROPFIND',
            '/caldav/principals/jana/',
            $this->propfind(['C:calendar-home-set', 'D:displayname']),
        );

        $xpath = $this->xpath($response->body);
        self::assertSame('/caldav/calendars/jana/', $this->nodeText($xpath, '//C:calendar-home-set/D:href'));
        self::assertSame('Jana', $this->nodeText($xpath, '//D:displayname'));
    }

    public function testThePrincipalOfSomebodyElseIsNotFound(): void
    {
        self::assertSame(404, $this->router->handle('PROPFIND', '/caldav/principals/peter/', '')->statusCode);
        self::assertSame(404, $this->router->handle('PROPFIND', '/caldav/calendars/peter/', '')->statusCode);
    }

    public function testTheHomeListsEveryCalendarAtDepthOne(): void
    {
        $response = $this->router->handle(
            'PROPFIND',
            '/caldav/calendars/jana/',
            $this->propfind(['D:resourcetype', 'D:displayname', 'CS:getctag']),
            ['Depth' => '1'],
        );

        $xpath = $this->xpath($response->body);
        $hrefs = $this->nodeTexts($xpath, '//D:response/D:href');

        self::assertContains('/caldav/calendars/jana/', $hrefs);
        self::assertContains('/caldav/calendars/jana/walk/', $hrefs);
        self::assertContains('/caldav/calendars/jana/reading/', $hrefs);

        self::assertSame(
            2,
            $this->nodeCount($xpath, '//D:response/D:propstat/D:prop/D:resourcetype/C:calendar'),
            'Both calendars, and not the home itself, are calendar collections',
        );
    }

    public function testTheHomeAtDepthZeroListsNoCalendars(): void
    {
        $response = $this->router->handle(
            'PROPFIND',
            '/caldav/calendars/jana/',
            $this->propfind(['D:displayname']),
        );

        self::assertSame(1, $this->nodeCount($this->xpath($response->body), '//D:response'));
    }

    // -------------------------------------------------------------------------
    // The change tag
    // -------------------------------------------------------------------------

    public function testACollectionCarriesItsCtag(): void
    {
        $response = $this->router->handle(
            'PROPFIND',
            '/caldav/calendars/jana/walk/',
            $this->propfind(['CS:getctag']),
        );

        self::assertSame('ctag-1', $this->nodeText($this->xpath($response->body), '//CS:getctag'));
    }

    public function testEachCollectionHasItsOwnCtagAndColour(): void
    {
        $response = $this->router->handle(
            'PROPFIND',
            '/caldav/calendars/jana/',
            $this->propfind(['CS:getctag', 'IC:calendar-color']),
            ['Depth' => '1'],
        );

        $xpath = $this->xpath($response->body);
        self::assertSame(
            'ctag-2',
            $this->nodeText($xpath, '//D:response[D:href="/caldav/calendars/jana/reading/"]//CS:getctag'),
        );
        self::assertSame(
            '#ffcc00',
            $this->nodeText($xpath, '//D:response[D:href="/caldav/calendars/jana/walk/"]//IC:calendar-color'),
        );
    }

    // -------------------------------------------------------------------------
    // Down to the events
    // -------------------------------------------------------------------------

    public function testACollectionStillAnswersPropfindAndReport(): void
    {
        $this->collection('walk')->putEvent('day-1', $this->event('day-1', '2026-09-25'));

        $propfind = $this->router->handle(
            'PROPFIND',
            '/caldav/calendars/jana/walk/',
            $this->propfind(['D:getetag']),
            ['Depth' => '1'],
        );

        $hrefs = $this->nodeTexts($this->xpath($propfind->body), '//D:response/D:href');
        self::assertContains('/caldav/calendars/jana/walk/day-1.ics', $hrefs);

        $report = $this->router->handle('REPORT', '/caldav/calendars/jana/walk/', implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:">',
            '  <D:prop><D:getetag/><C:calendar-data/></D:prop>',
            '  <C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT"/></C:comp-filter></C:filter>',
            '</C:calendar-query>',
        ]));

        self::assertSame(207, $report->statusCode);
        self::assertStringContainsString('BEGIN:VEVENT', $report->body);
    }

    public function testOneEventIsServedFromItsOwnUrl(): void
    {
        $this->collection('walk')->putEvent('day-1', $this->event('day-1', '2026-09-25'));

        $response = $this->router->handle('GET', '/caldav/calendars/jana/walk/day-1.ics');

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('BEGIN:VEVENT', $response->body);
    }

    public function testAnUnknownCalendarIsNotFound(): void
    {
        self::assertSame(404, $this->router->handle('PROPFIND', '/caldav/calendars/jana/ghost/', '')->statusCode);
        self::assertSame(404, $this->router->handle('GET', '/caldav/calendars/jana/ghost/day-1.ics')->statusCode);
        self::assertSame(404, $this->router->handle('PROPFIND', '/somewhere/else/')->statusCode);
    }

    // -------------------------------------------------------------------------
    // Read-only collections
    // -------------------------------------------------------------------------

    public function testAReadOnlyCollectionRefusesAWrite(): void
    {
        $response = $this->router->handle(
            'PUT',
            '/caldav/calendars/jana/reading/day-1.ics',
            "BEGIN:VEVENT\r\nUID:day-1\r\nDTSTART;VALUE=DATE:20260925\r\nEND:VEVENT",
        );

        self::assertSame(403, $response->statusCode);
        self::assertNull($this->collection('reading')->getEvent('day-1'));
    }

    public function testAReadOnlyCollectionSaysSoInItsPrivileges(): void
    {
        $response = $this->router->handle(
            'PROPFIND',
            '/caldav/calendars/jana/reading/',
            $this->propfind(['D:current-user-privileges']),
        );

        $xpath = $this->xpath($response->body);
        self::assertSame(1, $this->nodeCount($xpath, '//D:current-user-privileges/D:privilege/D:read'));
        self::assertSame(0, $this->nodeCount($xpath, '//D:current-user-privileges/D:privilege/D:write'));
    }

    public function testAWritableCollectionTakesAWrite(): void
    {
        $response = $this->router->handle(
            'PUT',
            '/caldav/calendars/jana/walk/day-2.ics',
            "BEGIN:VEVENT\r\nUID:day-2\r\nDTSTART;VALUE=DATE:20260926\r\nSUMMARY:Walk\r\nEND:VEVENT",
        );

        self::assertSame(201, $response->statusCode);
        self::assertNotNull($this->collection('walk')->getEvent('day-2'));
    }

    public function testAStoreCanRefuseOneWriteWithoutClosingTheCollection(): void
    {
        $collection = new RefusingCollection('walk', 'Ranná prechádzka', 'ctag-1');
        $collection->seed('day-1', $this->event('day-1', '2026-09-25'));

        $router = new CalDavRouter(new InMemoryCalendarHome('jana', 'Jana', [$collection]), '/caldav/');

        $put = $router->handle(
            'PUT',
            '/caldav/calendars/jana/walk/day-1.ics',
            "BEGIN:VEVENT\r\nUID:day-1\r\nDTSTART;VALUE=DATE:20200101\r\nEND:VEVENT",
        );

        self::assertSame(403, $put->statusCode);
        self::assertStringContainsString('need-privileges', $put->body);

        $delete = $router->handle('DELETE', '/caldav/calendars/jana/walk/day-1.ics');

        self::assertSame(403, $delete->statusCode);
    }

    public function testOptionsAdvertisesCalDav(): void
    {
        foreach (['/caldav/', '/caldav/principals/jana/', '/caldav/calendars/jana/', '/caldav/calendars/jana/walk/'] as $path) {
            $response = $this->router->handle('OPTIONS', $path);

            self::assertSame(200, $response->statusCode, $path);
            self::assertStringContainsString('calendar-access', $response->headers['DAV'], $path);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function collection(string $id): InMemoryCollection
    {
        $home = new \ReflectionProperty(CalDavRouter::class, 'home');
        /** @var InMemoryCalendarHome $value */
        $value = $home->getValue($this->router);
        $collection = $value->findCollection($id);

        self::assertInstanceOf(InMemoryCollection::class, $collection);

        return $collection;
    }

    private function event(string $uid, string $date): ICalEvent
    {
        return new ICalEvent(
            uid: $uid,
            dtStart: new DateTimeImmutable($date),
            dtEnd: null,
            summary: 'Day',
            description: null,
            location: null,
            rrule: null,
            allDay: true,
        );
    }

    /**
     * @param list<string> $properties
     */
    private function propfind(array $properties): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" '
                .'xmlns:CS="http://calendarserver.org/ns/" xmlns:IC="http://apple.com/ns/ical/"><D:prop>',
        ];
        foreach ($properties as $property) {
            $lines[] = '<' . $property . '/>';
        }
        $lines[] = '</D:prop></D:propfind>';

        return implode("\n", $lines);
    }
}

/**
 * One account's calendars, in memory, for tests only.
 */
final class InMemoryCalendarHome implements CalendarHomeInterface
{
    /** @var array<string, InMemoryCollection> */
    private array $collections = [];

    /**
     * @param list<InMemoryCollection> $collections
     */
    public function __construct(
        private readonly string $principalId,
        private readonly string $displayName,
        array $collections,
    ) {
        foreach ($collections as $collection) {
            $this->collections[$collection->getId()] = $collection;
        }
    }

    public function getPrincipalId(): string
    {
        return $this->principalId;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /** @return list<CalendarCollectionInterface> */
    public function listCollections(): array
    {
        return array_values($this->collections);
    }

    public function findCollection(string $id): ?CalendarCollectionInterface
    {
        return $this->collections[$id] ?? null;
    }
}

/**
 * One in-memory calendar collection, for tests only.
 */
class InMemoryCollection implements CalendarCollectionInterface, CalendarEventStoreInterface
{
    /** @var array<string, ICalEvent> */
    private array $events = [];

    public function __construct(
        private readonly string $id,
        private readonly string $displayName,
        private readonly string $ctag,
        private readonly ?string $color = null,
        private readonly bool $writable = true,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getDescription(): ?string
    {
        return null;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getCtag(): string
    {
        return $this->ctag;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function getStore(): CalendarEventStoreInterface
    {
        return $this;
    }

    public function putEvent(string $uid, ICalEvent $event): void
    {
        $this->events[$uid] = $event;
    }

    public function deleteEvent(string $uid): void
    {
        unset($this->events[$uid]);
    }

    public function getEvent(string $uid): ?ICalEvent
    {
        return $this->events[$uid] ?? null;
    }

    /** @return list<ICalEvent> */
    public function listEvents(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (ICalEvent $event) => ($to === null || $event->dtStart <= $to)
                && ($from === null || $event->dtEnd === null || $event->dtEnd >= $from),
        ));
    }
}

/**
 * A collection that takes writes in general and refuses this one — the shape
 * an application has when a resource is read-only or a date is out of range.
 */
final class RefusingCollection extends InMemoryCollection
{
    /**
     * Writes refused from outside still have to be arrangeable from inside —
     * a delete can only be refused if there is something there to delete.
     */
    public function seed(string $uid, ICalEvent $event): void
    {
        parent::putEvent($uid, $event);
    }

    public function putEvent(string $uid, ICalEvent $event): void
    {
        throw new \Tito10047\Calendar\Server\ForbiddenException('That day cannot be written.');
    }

    public function deleteEvent(string $uid): void
    {
        throw new \Tito10047\Calendar\Server\ForbiddenException('That day cannot be taken back.');
    }
}
