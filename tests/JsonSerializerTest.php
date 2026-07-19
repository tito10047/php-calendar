<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\EventStatus;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\Serializer\JsonSerializer;

class JsonSerializerTest extends TestCase
{
    /**
     * @param list<string> $categories
     */
    private function makeEvent(
        string $uid,
        string $start,
        ?string $end = null,
        ?string $summary = null,
        ?string $color = null,
        array $categories = [],
        ?EventStatus $status = null,
        ?RecurrenceRule $rrule = null,
    ): ICalEvent {
        return new ICalEvent(
            uid:         $uid,
            dtStart:     new DateTimeImmutable($start),
            dtEnd:       $end !== null ? new DateTimeImmutable($end) : null,
            summary:     $summary,
            description: null,
            location:    null,
            rrule:       $rrule,
            color:       $color,
            categories:  $categories,
            status:      $status,
        );
    }

    public function testBasicEventSerialization(): void
    {
        $event  = $this->makeEvent('uid-1', '2025-06-01T10:00:00Z', '2025-06-01T11:00:00Z', 'Team meeting');
        $result = JsonSerializer::fromEvents([$event])->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('uid-1', $result[0]['id']);
        $this->assertSame('Team meeting', $result[0]['title']);
        $this->assertFalse($result[0]['allDay']);
        $this->assertSame('2025-06-01T10:00:00', $result[0]['start']);
        $this->assertSame('2025-06-01T11:00:00', $result[0]['end']);
    }

    public function testAllDayEvent(): void
    {
        $event  = $this->makeEvent('uid-2', '2025-06-01', '2025-06-03', 'Holiday');
        $result = JsonSerializer::fromEvents([$event])->toArray();

        $this->assertTrue($result[0]['allDay']);
        $this->assertSame('2025-06-01', $result[0]['start']);
        $this->assertSame('2025-06-03', $result[0]['end']);
    }

    public function testColorAndCategoriesAndStatus(): void
    {
        $event = $this->makeEvent(
            'uid-3',
            '2025-06-01T09:00:00Z',
            null,
            'Dovolenka',
            '#e74c3c',
            ['Osobné', 'Voľno'],
            EventStatus::Confirmed,
        );
        $result = JsonSerializer::fromEvents([$event])->toArray();

        $this->assertSame('#e74c3c', $result[0]['color']);
        $this->assertSame(['Osobné', 'Voľno'], $result[0]['extendedProps']['categories']);
        $this->assertSame('CONFIRMED', $result[0]['extendedProps']['status']);
    }

    public function testForRangeFiltersEvents(): void
    {
        $events = [
            $this->makeEvent('before', '2025-05-31T10:00:00Z', null, 'Before'),
            $this->makeEvent('in-range', '2025-06-15T10:00:00Z', null, 'In range'),
            $this->makeEvent('after', '2025-07-01T10:00:00Z', null, 'After'),
        ];

        $result = JsonSerializer::fromEvents($events)
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('in-range', $result[0]['id']);
    }

    public function testRecurringEventExpandedInRange(): void
    {
        $rule = RecurrenceRule::weekly()
            ->onDays(\Tito10047\Calendar\Enum\DayName::Monday)
            ->limitTo(10);

        $event  = $this->makeEvent('weekly-mon', '2025-06-02T09:00:00Z', null, 'Weekly Monday', rrule: $rule);
        $result = JsonSerializer::fromEvents([$event])
            ->forRange(new DateTimeImmutable('2025-06-01'), new DateTimeImmutable('2025-06-30'))
            ->toArray();

        // Mondays in June 2025: 2, 9, 16, 23, 30
        $this->assertCount(5, $result);
        $starts = array_column($result, 'start');
        $this->assertContains('2025-06-02T09:00:00', $starts);
        $this->assertContains('2025-06-30T09:00:00', $starts);
    }

    public function testNullEndProducesNullInOutput(): void
    {
        $event  = $this->makeEvent('no-end', '2025-06-01T10:00:00Z', null, 'No end');
        $result = JsonSerializer::fromEvents([$event])->toArray();

        $this->assertNull($result[0]['end']);
    }

    public function testToJsonProducesValidJson(): void
    {
        $event = $this->makeEvent('uid-json', '2025-06-01T10:00:00Z', '2025-06-01T11:00:00Z', 'JSON test');
        $json  = JsonSerializer::fromEvents([$event])->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('uid-json', $decoded[0]['id']);
    }

    public function testExtendedPropsStructure(): void
    {
        $event = new ICalEvent(
            uid:         'ext-test',
            dtStart:     new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:       null,
            summary:     'Test',
            description: 'Some description',
            location:    'Bratislava',
            rrule:       null,
            url:         'https://example.com',
        );

        $result = JsonSerializer::fromEvents([$event])->toArray();

        $this->assertSame('Some description', $result[0]['extendedProps']['description']);
        $this->assertSame('Bratislava', $result[0]['extendedProps']['location']);
        $this->assertSame('https://example.com', $result[0]['extendedProps']['url']);
    }

    public function testFullCalendarCompatibleOutput(): void
    {
        // Simulate a FullCalendar-ready feed with multiple events
        $events = [
            $this->makeEvent('ev-1', '2025-06-10T09:00:00Z', '2025-06-10T10:00:00Z', 'Meeting', '#3498db'),
            $this->makeEvent('ev-2', '2025-06-15', '2025-06-17', 'Conference'),
        ];

        $json    = JsonSerializer::fromEvents($events)->toJson();
        $decoded = json_decode($json, true);

        $this->assertCount(2, $decoded);
        $this->assertFalse($decoded[0]['allDay']);
        $this->assertTrue($decoded[1]['allDay']);
        $this->assertSame('#3498db', $decoded[0]['color']);
        $this->assertNull($decoded[1]['color']);
    }

    public function testRoundTripWithICalParser(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:parsed-event@test',
            'DTSTART:20250615T100000Z',
            'DTEND:20250615T110000Z',
            'SUMMARY:Parsed meeting',
            'COLOR:#e74c3c',
            'CATEGORIES:Work,Important',
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = (new ICalParser())->parseString($ics);
        $result = JsonSerializer::fromEvents($events)->toArray();

        $this->assertCount(1, $result);
        $this->assertSame('parsed-event@test', $result[0]['id']);
        $this->assertSame('Parsed meeting', $result[0]['title']);
        $this->assertSame('#e74c3c', $result[0]['color']);
        $this->assertSame(['Work', 'Important'], $result[0]['extendedProps']['categories']);
        $this->assertSame('CONFIRMED', $result[0]['extendedProps']['status']);
    }
}
