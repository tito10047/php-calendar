<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tito10047\Calendar\Enum\EventClass;
use Tito10047\Calendar\Enum\EventTransp;
use Tito10047\Calendar\ICal\ICalEvent;
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\Serializer\JsonSerializer;

final class P2FeaturesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // P2.13: X-* extension properties
    // -------------------------------------------------------------------------

    public function testExtensionPropertiesRoundTrip(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" .
               "UID:xtest@test\r\nDTSTART:20250601T100000Z\r\n" .
               "SUMMARY:X-prop test\r\n" .
               "X-GOOGLE-CALENDAR-CONTENT-DISPLAY:chip\r\n" .
               "X-APPLE-TRAVEL-ADVISORY-BEHAVIOR:AUTOMATIC\r\n" .
               "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $parser = new ICalParser();
        $events = $parser->parseString($ics);
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertSame('chip', $event->getExtendedProperty('X-GOOGLE-CALENDAR-CONTENT-DISPLAY'));
        $this->assertSame('AUTOMATIC', $event->getExtendedProperty('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR'));
        $this->assertNull($event->getExtendedProperty('X-NONEXISTENT'));
    }

    public function testExtensionPropertiesExported(): void
    {
        $event = new ICalEvent(
            uid:                 'xexport@test',
            dtStart:             new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:               new DateTimeImmutable('2025-06-01T11:00:00Z'),
            summary:             'X export test',
            description:         null,
            location:            null,
            rrule:               null,
            extensionProperties: ['X-CUSTOM-PROP' => 'hello', 'X-OTHER' => 'world'],
        );

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringContainsString('X-CUSTOM-PROP:hello', $ics);
        $this->assertStringContainsString('X-OTHER:world', $ics);
    }

    // -------------------------------------------------------------------------
    // P2.15: TRANSP, CLASS, PRIORITY
    // -------------------------------------------------------------------------

    public function testTranspClassPriorityParsed(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" .
               "UID:meta@test\r\nDTSTART:20250601T100000Z\r\n" .
               "SUMMARY:Meta test\r\nTRANSP:TRANSPARENT\r\nCLASS:PRIVATE\r\nPRIORITY:3\r\n" .
               "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $events = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $events);
        $e = $events[0];

        $this->assertSame(EventTransp::Transparent, $e->transp);
        $this->assertSame(EventClass::Private, $e->classification);
        $this->assertSame(3, $e->priority);
    }

    public function testTranspClassPriorityRoundTrip(): void
    {
        $event = new ICalEvent(
            uid:            'p2round@test',
            dtStart:        new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:          new DateTimeImmutable('2025-06-01T11:00:00Z'),
            summary:        'Round-trip test',
            description:    null,
            location:       null,
            rrule:          null,
            transp:         EventTransp::Opaque,
            classification: EventClass::Confidential,
            priority:       5,
        );

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringContainsString('TRANSP:OPAQUE', $ics);
        $this->assertStringContainsString('CLASS:CONFIDENTIAL', $ics);
        $this->assertStringContainsString('PRIORITY:5', $ics);

        $reparsed = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $reparsed);
        $r = $reparsed[0];
        $this->assertSame(EventTransp::Opaque, $r->transp);
        $this->assertSame(EventClass::Confidential, $r->classification);
        $this->assertSame(5, $r->priority);
    }

    public function testDefaultPriorityZeroNotExported(): void
    {
        $event = new ICalEvent(
            uid:     'p0@test',
            dtStart: new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:   null,
            summary: 'No priority',
            description: null,
            location:    null,
            rrule:       null,
        );
        $ics = (new ICalExporter())->addICalEvent($event)->export();
        $this->assertStringNotContainsString('PRIORITY:', $ics);
    }

    // -------------------------------------------------------------------------
    // P2.17: DTSTAMP, CREATED, LAST-MODIFIED, SEQUENCE
    // -------------------------------------------------------------------------

    public function testCalDAVMetadataParsed(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" .
               "UID:cadav@test\r\nDTSTART:20250601T100000Z\r\n" .
               "SUMMARY:CalDAV test\r\n" .
               "DTSTAMP:20250101T000000Z\r\n" .
               "CREATED:20250102T000000Z\r\n" .
               "LAST-MODIFIED:20250103T000000Z\r\n" .
               "SEQUENCE:7\r\n" .
               "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $events = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $events);
        $e = $events[0];

        $this->assertNotNull($e->dtStamp);
        $this->assertSame('20250101', $e->dtStamp->format('Ymd'));
        $this->assertNotNull($e->created);
        $this->assertSame('20250102', $e->created->format('Ymd'));
        $this->assertNotNull($e->lastModified);
        $this->assertSame('20250103', $e->lastModified->format('Ymd'));
        $this->assertSame(7, $e->sequence);
    }

    public function testCalDAVMetadataExported(): void
    {
        $event = new ICalEvent(
            uid:          'cadavexp@test',
            dtStart:      new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:        null,
            summary:      'CalDAV export',
            description:  null,
            location:     null,
            rrule:        null,
            created:      new DateTimeImmutable('2025-01-02T00:00:00Z'),
            lastModified: new DateTimeImmutable('2025-01-03T00:00:00Z'),
            sequence:     3,
        );

        $ics = (new ICalExporter())->addICalEvent($event)->export();

        $this->assertStringContainsString('CREATED:20250102T000000Z', $ics);
        $this->assertStringContainsString('LAST-MODIFIED:20250103T000000Z', $ics);
        $this->assertStringContainsString('SEQUENCE:3', $ics);
    }

    public function testDefaultSequenceZeroNotExported(): void
    {
        $event = new ICalEvent(
            uid:     'seq0@test',
            dtStart: new DateTimeImmutable('2025-06-01T10:00:00Z'),
            dtEnd:   null,
            summary: 'No sequence',
            description: null,
            location:    null,
            rrule:       null,
        );
        $ics = (new ICalExporter())->addICalEvent($event)->export();
        $this->assertStringNotContainsString('SEQUENCE:', $ics);
    }

    // -------------------------------------------------------------------------
    // P2.12: RECURRENCE-ID (parse + expand)
    // -------------------------------------------------------------------------

    public function testRecurrenceIdParsedAndAttachedToMaster(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
               // Master event: weekly on Mondays
               "BEGIN:VEVENT\r\nUID:rec@test\r\n" .
               "DTSTART:20250106T100000Z\r\n" .
               "DTEND:20250106T110000Z\r\n" .
               "RRULE:FREQ=WEEKLY;COUNT=4\r\n" .
               "SUMMARY:Weekly\r\n" .
               "END:VEVENT\r\n" .
               // Override: 2nd occurrence (Jan 13) moved to Jan 14 11:00
               "BEGIN:VEVENT\r\nUID:rec@test\r\n" .
               "RECURRENCE-ID:20250113T100000Z\r\n" .
               "DTSTART:20250114T110000Z\r\n" .
               "DTEND:20250114T120000Z\r\n" .
               "SUMMARY:Weekly (moved)\r\n" .
               "END:VEVENT\r\n" .
               "END:VCALENDAR\r\n";

        $events = (new ICalParser())->parseString($ics);
        // Parser should return only 1 master event (override merged in)
        $this->assertCount(1, $events);
        $master = $events[0];
        $this->assertCount(1, $master->modifiedOccurrences);
        $this->assertArrayHasKey('2025-01-13', $master->modifiedOccurrences);
    }

    public function testExpandOccurrencesWithRecurrenceIdOverride(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
               "BEGIN:VEVENT\r\nUID:rec2@test\r\n" .
               "DTSTART:20250106T100000Z\r\n" .
               "DTEND:20250106T110000Z\r\n" .
               "RRULE:FREQ=WEEKLY;COUNT=3\r\n" .
               "SUMMARY:Weekly\r\n" .
               "END:VEVENT\r\n" .
               "BEGIN:VEVENT\r\nUID:rec2@test\r\n" .
               "RECURRENCE-ID:20250113T100000Z\r\n" .
               "DTSTART:20250113T140000Z\r\n" .
               "DTEND:20250113T150000Z\r\n" .
               "SUMMARY:Weekly (rescheduled)\r\n" .
               "END:VEVENT\r\n" .
               "END:VCALENDAR\r\n";

        $events = (new ICalParser())->parseString($ics);
        $master = $events[0];

        $from       = new DateTimeImmutable('2025-01-06');
        $to         = new DateTimeImmutable('2025-01-31');
        $occurrences = $master->expandOccurrences($from, $to);

        // 3 occurrences total: Jan 6, Jan 13 (overridden), Jan 20
        $this->assertCount(3, $occurrences);

        // Jan 6 — normal
        $this->assertSame('2025-01-06', $occurrences[0]->dtStart->format('Y-m-d'));
        $this->assertSame('10:00', $occurrences[0]->dtStart->format('H:i'));

        // Jan 13 — overridden to 14:00
        $this->assertSame('2025-01-13', $occurrences[1]->dtStart->format('Y-m-d'));
        $this->assertSame('14:00', $occurrences[1]->dtStart->format('H:i'));
        $this->assertSame('Weekly (rescheduled)', $occurrences[1]->summary);

        // Jan 20 — normal
        $this->assertSame('2025-01-20', $occurrences[2]->dtStart->format('Y-m-d'));
    }

    public function testRecurrenceIdExported(): void
    {
        $override = new ICalEvent(
            uid:          'recexp@test',
            dtStart:      new DateTimeImmutable('2025-01-13T14:00:00Z'),
            dtEnd:        new DateTimeImmutable('2025-01-13T15:00:00Z'),
            summary:      'Overridden',
            description:  null,
            location:     null,
            rrule:        null,
            recurrenceId: new DateTimeImmutable('2025-01-13T10:00:00Z'),
        );

        $ics = (new ICalExporter())->addICalEvent($override)->export();
        $this->assertStringContainsString('RECURRENCE-ID:20250113T100000Z', $ics);
    }

    // -------------------------------------------------------------------------
    // P2.16: DST-aware timezone handling in expandOccurrences
    // -------------------------------------------------------------------------

    public function testDstAwareOccurrenceExpansion(): void
    {
        // Event in Europe/Berlin that spans a DST change (end of March)
        $dtStart = new DateTimeImmutable('2025-03-01T10:00:00', new \DateTimeZone('Europe/Berlin'));
        $dtEnd   = new DateTimeImmutable('2025-03-01T11:00:00', new \DateTimeZone('Europe/Berlin'));

        $rule  = \Tito10047\Calendar\Recurrence\RecurrenceRule::weekly();
        $event = new ICalEvent(
            uid:     'dst@test',
            dtStart: $dtStart,
            dtEnd:   $dtEnd,
            summary: 'DST test',
            description: null,
            location:    null,
            rrule:       $rule,
        );

        $from  = new DateTimeImmutable('2025-03-01', new \DateTimeZone('Europe/Berlin'));
        $to    = new DateTimeImmutable('2025-04-05', new \DateTimeZone('Europe/Berlin'));
        $occ   = $event->expandOccurrences($from, $to);

        // ~5-6 weekly occurrences; each should be at 10:00 local Berlin time
        $this->assertGreaterThan(0, count($occ));
        foreach ($occ as $o) {
            $localTime = $o->dtStart->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('H:i');
            $this->assertSame('10:00', $localTime, "Occurrence on {$o->dtStart->format('Y-m-d')} should be at 10:00 Berlin");
        }
    }

    // -------------------------------------------------------------------------
    // P2.14: WKST parsed and round-tripped
    // -------------------------------------------------------------------------

    public function testWkstRoundTripViaRrule(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" .
               "UID:wkst@test\r\nDTSTART:20250106T100000Z\r\n" .
               "SUMMARY:WKST test\r\nRRULE:FREQ=WEEKLY;BYDAY=MO,TU;WKST=SU\r\n" .
               "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $events = (new ICalParser())->parseString($ics);
        $this->assertCount(1, $events);
        $e = $events[0];
        $this->assertNotNull($e->rrule);

        $ics2 = (new ICalExporter())->addICalEvent($e)->export();
        $this->assertStringContainsString('WKST=SU', $ics2);
    }

    // -------------------------------------------------------------------------
    // JsonSerializer uses expandOccurrences (RECURRENCE-ID aware)
    // -------------------------------------------------------------------------

    public function testJsonSerializerRespectsRecurrenceIdOverride(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
               "BEGIN:VEVENT\r\nUID:jrec@test\r\n" .
               "DTSTART:20250106T100000Z\r\nDTEND:20250106T110000Z\r\n" .
               "RRULE:FREQ=WEEKLY;COUNT=2\r\nSUMMARY:Weekly\r\n" .
               "END:VEVENT\r\n" .
               "BEGIN:VEVENT\r\nUID:jrec@test\r\n" .
               "RECURRENCE-ID:20250113T100000Z\r\n" .
               "DTSTART:20250113T150000Z\r\nDTEND:20250113T160000Z\r\n" .
               "SUMMARY:Weekly (moved)\r\n" .
               "END:VEVENT\r\n" .
               "END:VCALENDAR\r\n";

        $events = (new ICalParser())->parseString($ics);
        $from   = new DateTimeImmutable('2025-01-06');
        $to     = new DateTimeImmutable('2025-01-20');
        $arr    = JsonSerializer::fromEvents($events)->forRange($from, $to)->toArray();

        $this->assertCount(2, $arr);

        $titles = array_column($arr, 'title');
        $this->assertContains('Weekly', $titles);
        $this->assertContains('Weekly (moved)', $titles);

        // The overridden occurrence should show 15:00, not 10:00
        foreach ($arr as $entry) {
            if ($entry['title'] === 'Weekly (moved)') {
                $this->assertStringContainsString('T15:00:00', $entry['start']);
            }
        }
    }
}
