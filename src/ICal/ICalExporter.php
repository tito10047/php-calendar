<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Fluent, immutable RFC 5545 iCal exporter.
 *
 * All mutating methods (calendarName, addEvent, …) return a new clone — the
 * original instance is never modified.
 *
 * Timezone handling:
 *   - UTC datetimes are serialised with the Z suffix: DTSTART:20241101T120000Z
 *   - Named IANA timezone datetimes keep their zone: DTSTART;TZID=Europe/London:20241101T120000
 *   - Numeric-offset timezones (+01:00) are normalised to UTC on export (offset information
 *     is lost — pass a proper DateTimeZone('Europe/Berlin') if you need to preserve the zone).
 *
 * Usage:
 *   $ics = (new ICalExporter())
 *       ->calendarName('My Calendar')
 *       ->addEvent(title: 'Team meeting', from: $from, to: $to)
 *       ->addRecurringEvent(title: 'Standup', rule: $rule, start: $start)
 *       ->export();
 *
 *   header('Content-Type: text/calendar; charset=utf-8');
 *   header('Content-Disposition: attachment; filename="calendar.ics"');
 *   echo $ics;
 */
final class ICalExporter
{
    /** @var list<ICalEvent> */
    private array $events = [];

    private string $calendarName = 'Calendar';
    private string $prodId       = '-//php-calendar//php-calendar 2.0//EN';

    public function calendarName(string $name): self
    {
        $clone = clone $this;
        $clone->calendarName = $name;
        return $clone;
    }

    public function addEvent(
        string $title,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to = null,
        ?string $description = null,
        ?string $location = null,
        ?string $uid = null,
    ): self {
        $clone           = clone $this;
        $clone->events[] = new ICalEvent(
            uid:         $uid ?? $this->generateUid(),
            dtStart:     $from,
            dtEnd:       $to,
            summary:     $title,
            description: $description,
            location:    $location,
            rrule:       null,
        );
        return $clone;
    }

    public function addRecurringEvent(
        string $title,
        RecurrenceRule $rule,
        DateTimeImmutable $start,
        ?string $description = null,
        ?string $location = null,
        ?string $uid = null,
    ): self {
        $clone           = clone $this;
        $clone->events[] = new ICalEvent(
            uid:         $uid ?? $this->generateUid(),
            dtStart:     $start,
            dtEnd:       null,
            summary:     $title,
            description: $description,
            location:    $location,
            rrule:       $rule,
        );
        return $clone;
    }

    public function addICalEvent(ICalEvent $event): self
    {
        $clone           = clone $this;
        $clone->events[] = $event;
        return $clone;
    }

    public function export(): string
    {
        $now   = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->prodId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escapeText($this->calendarName),
        ];

        foreach ($this->events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event->uid;
            $lines[] = 'DTSTAMP:' . $now->format('Ymd\THis\Z');
            $lines[] = $this->formatDtProp('DTSTART', $event->dtStart);

            if ($event->dtEnd !== null) {
                $lines[] = $this->formatDtProp('DTEND', $event->dtEnd);
            }

            $lines[] = 'SUMMARY:' . $this->escapeText($event->summary ?? '');

            if ($event->description !== null) {
                $lines[] = 'DESCRIPTION:' . $this->escapeText($event->description);
            }
            if ($event->location !== null) {
                $lines[] = 'LOCATION:' . $this->escapeText($event->location);
            }
            if ($event->rrule !== null) {
                $lines[] = 'RRULE:' . $event->rrule->toRruleString();
            }

            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $this->fold($lines)) . "\r\n";
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Serialise a datetime property respecting the original timezone.
     *
     * UTC / numeric-offset → "PROPNAME:YYYYMMDDTHHmmssZ"
     * Named IANA timezone  → "PROPNAME;TZID=Zone/Name:YYYYMMDDTHHmmss"
     */
    private function formatDtProp(string $propName, DateTimeImmutable $dt): string
    {
        $tzName = $dt->getTimezone()->getName();

        // Numeric offset (e.g. +01:00, -05:30) — normalise to UTC
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $tzName)) {
            $tzName = 'UTC';
        }

        if ($tzName === 'UTC') {
            return $propName . ':' . $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }

        // Named IANA timezone — preserve it with TZID parameter
        return $propName . ';TZID=' . $tzName . ':' . $dt->format('Ymd\THis');
    }

    /**
     * Fold long lines at 75 octets per RFC 5545 §3.1.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function fold(array $lines): array
    {
        $folded = [];
        foreach ($lines as $line) {
            while (strlen($line) > 75) {
                $folded[] = substr($line, 0, 75);
                $line     = ' ' . substr($line, 75);
            }
            $folded[] = $line;
        }
        return $folded;
    }

    private function escapeText(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\;', '\,', '\n'],
            $text,
        );
    }

    private function generateUid(): string
    {
        return sprintf('%s@php-calendar', bin2hex(random_bytes(16)));
    }
}
