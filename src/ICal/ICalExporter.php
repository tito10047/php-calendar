<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Fluent RFC 5545 iCal exporter.
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
    /** @var list<array<string, mixed>> */
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
        $clone->events[] = [
            'title'       => $title,
            'from'        => $from,
            'to'          => $to,
            'description' => $description,
            'location'    => $location,
            'uid'         => $uid ?? $this->generateUid(),
            'rrule'       => null,
        ];
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
        $clone->events[] = [
            'title'       => $title,
            'from'        => $start,
            'to'          => null,
            'description' => $description,
            'location'    => $location,
            'uid'         => $uid ?? $this->generateUid(),
            'rrule'       => $rule,
        ];
        return $clone;
    }

    public function addICalEvent(ICalEvent $event): self
    {
        $clone           = clone $this;
        $clone->events[] = [
            'title'       => $event->summary ?? '',
            'from'        => $event->dtStart,
            'to'          => $event->dtEnd,
            'description' => $event->description,
            'location'    => $event->location,
            'uid'         => $event->uid,
            'rrule'       => $event->rrule,
        ];
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
            $lines[] = 'UID:' . $event['uid'];
            $lines[] = 'DTSTAMP:' . $now->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $event['from']->format('Ymd\THis\Z');

            if ($event['to'] !== null) {
                $lines[] = 'DTEND:' . $event['to']->format('Ymd\THis\Z');
            }

            $lines[] = 'SUMMARY:' . $this->escapeText((string) $event['title']);

            if ($event['description'] !== null) {
                $lines[] = 'DESCRIPTION:' . $this->escapeText($event['description']);
            }
            if ($event['location'] !== null) {
                $lines[] = 'LOCATION:' . $this->escapeText($event['location']);
            }
            if ($event['rrule'] instanceof RecurrenceRule) {
                $lines[] = 'RRULE:' . $event['rrule']->toRruleString();
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
