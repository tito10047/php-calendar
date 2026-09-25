<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use DateTimeZone;
use Tito10047\Calendar\Enum\EventStatus;
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
 *     and a matching VTIMEZONE component is emitted (disable with withTimezoneDefinitions(false)).
 *   - Numeric-offset timezones (+01:00) are normalised to UTC on export (offset information
 *     is lost — pass a proper DateTimeZone('Europe/Berlin') if you need to preserve the zone).
 *   - All-day events are written as DATE values: DTSTART;VALUE=DATE:20241101
 *   - DTSTAMP, CREATED and LAST-MODIFIED are always converted to UTC.
 *
 * Every user-supplied value is escaped (TEXT), quoted (parameters) or stripped of control
 * characters (URIs, addresses), so untrusted input cannot inject properties or components.
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

    private string $calendarName          = 'Calendar';
    private string $prodId                = '-//php-calendar//php-calendar 3.0//EN';
    private bool $timezoneDefinitions     = true;

    public function calendarName(string $name): self
    {
        $clone = clone $this;
        $clone->calendarName = $name;
        return $clone;
    }

    /** Emit VTIMEZONE components for every named timezone used (default: on). */
    public function withTimezoneDefinitions(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->timezoneDefinitions = $enabled;
        return $clone;
    }

    /**
     * @param list<string> $categories
     * @param bool         $allDay     Write DTSTART/DTEND as DATE values; $to is the exclusive end date
     */
    public function addEvent(
        string $title,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to = null,
        ?string $description = null,
        ?string $location = null,
        ?string $uid = null,
        ?string $url = null,
        ?string $color = null,
        array $categories = [],
        ?EventStatus $status = null,
        bool $allDay = false,
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
            url:         $url,
            color:       $color,
            categories:  $categories,
            status:      $status,
            allDay:      $allDay,
        );
        return $clone;
    }

    /**
     * @param list<string> $categories
     */
    public function addRecurringEvent(
        string $title,
        RecurrenceRule $rule,
        DateTimeImmutable $start,
        ?string $description = null,
        ?string $location = null,
        ?string $uid = null,
        ?string $url = null,
        ?string $color = null,
        array $categories = [],
        ?EventStatus $status = null,
        ?DateTimeImmutable $end = null,
        bool $allDay = false,
    ): self {
        $clone           = clone $this;
        $clone->events[] = new ICalEvent(
            uid:         $uid ?? $this->generateUid(),
            dtStart:     $start,
            dtEnd:       $end,
            summary:     $title,
            description: $description,
            location:    $location,
            rrule:       $rule,
            url:         $url,
            color:       $color,
            categories:  $categories,
            status:      $status,
            allDay:      $allDay,
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
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lines = $this->headerLines();

        foreach ($this->events as $event) {
            array_push($lines, ...$this->buildEventLines($event, $now));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", ICalFormatter::fold($lines)) . "\r\n";
    }

    /**
     * Write the iCal output to an open writable stream without building the
     * entire string in memory — suitable for large calendars (10 000+ events).
     *
     * @param resource $stream A writable stream resource (e.g. fopen(), php://output).
     */
    public function exportToStream($stream): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->writeLines($stream, $this->headerLines());

        foreach ($this->events as $event) {
            $this->writeLines($stream, $this->buildEventLines($event, $now));
        }

        fwrite($stream, "END:VCALENDAR\r\n");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function headerLines(): array
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . ICalFormatter::text($this->prodId),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . ICalFormatter::text($this->calendarName),
        ];

        if ($this->timezoneDefinitions) {
            foreach ($this->collectTimezones() as $tzName => [$fromYear, $toYear]) {
                array_push($lines, ...$this->buildTimezoneLines(new DateTimeZone($tzName), $fromYear, $toYear));
            }
        }

        return $lines;
    }

    /**
     * Build the RFC 5545 lines for a VEVENT (unfolded) followed by its RECURRENCE-ID overrides.
     *
     * @return list<string>
     */
    private function buildEventLines(ICalEvent $event, DateTimeImmutable $now, ?ICalEvent $master = null): array
    {
        $utc     = new DateTimeZone('UTC');
        $uid     = $master !== null ? $master->uid : $event->uid;
        $lines   = [];
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . ICalFormatter::text($uid);
        $lines[] = 'DTSTAMP:' . ($event->dtStamp ?? $now)->setTimezone($utc)->format('Ymd\THis\Z');
        if ($event->created !== null) {
            $lines[] = 'CREATED:' . $event->created->setTimezone($utc)->format('Ymd\THis\Z');
        }
        if ($event->lastModified !== null) {
            $lines[] = 'LAST-MODIFIED:' . $event->lastModified->setTimezone($utc)->format('Ymd\THis\Z');
        }
        if ($event->sequence !== 0) {
            $lines[] = 'SEQUENCE:' . $event->sequence;
        }
        $lines[] = $this->formatDtProp('DTSTART', $event->dtStart, $event->allDay);
        if ($event->dtEnd !== null) {
            $lines[] = $this->formatDtProp('DTEND', $event->dtEnd, $event->allDay);
        }
        if ($event->recurrenceId !== null) {
            $isDate  = $master !== null ? $master->allDay : $event->allDay;
            $range   = $event->thisAndFuture ? ';RANGE=THISANDFUTURE' : '';
            $lines[] = $this->formatDtProp('RECURRENCE-ID' . $range, $event->recurrenceId, $isDate);
        }
        $lines[] = 'SUMMARY:' . ICalFormatter::text($event->summary ?? '');
        if ($event->description !== null) {
            $lines[] = 'DESCRIPTION:' . ICalFormatter::text($event->description);
        }
        if ($event->location !== null) {
            $lines[] = 'LOCATION:' . ICalFormatter::text($event->location);
        }
        if ($event->url !== null) {
            $lines[] = 'URL:' . ICalFormatter::value($event->url);
        }
        if ($event->color !== null) {
            $lines[] = 'COLOR:' . ICalFormatter::text($event->color);
        }
        if ($event->categories !== []) {
            $lines[] = 'CATEGORIES:' . implode(',', array_map(ICalFormatter::text(...), $event->categories));
        }
        if ($event->status !== null) {
            $lines[] = 'STATUS:' . $event->status->value;
        }
        if ($event->transp !== null) {
            $lines[] = 'TRANSP:' . $event->transp->value;
        }
        if ($event->classification !== null) {
            $lines[] = 'CLASS:' . $event->classification->value;
        }
        if ($event->priority !== 0) {
            $lines[] = 'PRIORITY:' . $event->priority;
        }
        if ($event->rrule !== null) {
            $lines[] = 'RRULE:' . $event->rrule->toRruleString($event->dtStart, $event->allDay);
            foreach ($this->uniqueDates([...$event->exDates, ...$event->rrule->getExDates()]) as $exDate) {
                $lines[] = $this->formatDtProp('EXDATE', $this->resolveDate($exDate, $event), $event->allDay);
            }
            foreach ($this->uniqueDates($event->rrule->getExtraDates()) as $rDate) {
                $lines[] = $this->formatDtProp('RDATE', $this->resolveDate($rDate, $event), $event->allDay);
            }
        }
        if ($event->organizer !== null) {
            $orgLine = 'ORGANIZER';
            if ($event->organizerName !== null) {
                $orgLine .= ';CN=' . ICalFormatter::param($event->organizerName);
            }
            $orgLine .= ':mailto:' . ICalFormatter::value($event->organizer);
            $lines[] = $orgLine;
        }
        foreach ($event->attendees as $attendee) {
            $lines[] = $attendee->toIcalLine();
        }
        foreach ($event->extensionProperties as $xName => $xValue) {
            $name = ICalFormatter::token((string) $xName, 'X-UNKNOWN');
            if (!str_starts_with($name, 'X-')) {
                $name = 'X-' . $name;
            }
            $lines[] = $name . ':' . ICalFormatter::text($xValue);
        }
        foreach ($event->alarms as $alarm) {
            foreach (explode("\r\n", $alarm->toIcalLines()) as $alarmLine) {
                $lines[] = $alarmLine;
            }
        }
        $lines[] = 'END:VEVENT';

        // RECURRENCE-ID overrides are separate components sharing the master's UID
        if ($master === null) {
            foreach ($event->modifiedOccurrences as $key => $override) {
                if ($override->recurrenceId === null) {
                    $override = $this->withRecurrenceId($override, $event, (string) $key);
                }
                array_push($lines, ...$this->buildEventLines($override, $now, $event));
            }
        }

        return $lines;
    }

    /**
     * Fold and write a list of lines to a stream.
     *
     * @param resource     $stream
     * @param list<string> $lines
     */
    private function writeLines($stream, array $lines): void
    {
        foreach (ICalFormatter::fold($lines) as $line) {
            fwrite($stream, $line . "\r\n");
        }
    }

    /**
     * Serialise a date / datetime property respecting the original timezone.
     *
     * DATE                 → "PROPNAME;VALUE=DATE:YYYYMMDD"
     * UTC / numeric-offset → "PROPNAME:YYYYMMDDTHHmmssZ"
     * Named IANA timezone  → "PROPNAME;TZID=Zone/Name:YYYYMMDDTHHmmss"
     */
    private function formatDtProp(string $propName, DateTimeImmutable $dt, bool $isDate = false): string
    {
        if ($isDate) {
            return $propName . ';VALUE=DATE:' . $dt->format('Ymd');
        }

        $tzName = $this->namedTimezone($dt);
        if ($tzName === null) {
            return $propName . ':' . $dt->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }

        // Named IANA timezone — preserve it with TZID parameter
        return $propName . ';TZID=' . ICalFormatter::param($tzName) . ':' . $dt->format('Ymd\THis');
    }

    /** IANA name of the value's timezone, or null for UTC / fixed offsets / abbreviations. */
    private function namedTimezone(DateTimeImmutable $dt): ?string
    {
        $tz     = $dt->getTimezone();
        $tzName = $tz->getName();
        $location = $tz->getLocation();
        if ($tzName === 'UTC' || $tzName === 'Z' || $location === false || preg_match('/^[+-]\d{2}:\d{2}$/', $tzName)) {
            return null;
        }
        return $tzName;
    }

    /**
     * Named timezones used by the events, with the year range they are needed for.
     *
     * @return array<string, array{int, int}>
     */
    private function collectTimezones(): array
    {
        $zones = [];
        $visit = function (ICalEvent $event) use (&$zones, &$visit): void {
            foreach ($event->modifiedOccurrences as $override) {
                $visit($override);
            }
            if ($event->allDay) {
                return; // DATE values carry no timezone
            }
            $dates = [$event->dtStart, $event->dtEnd, $event->recurrenceId, ...$event->exDates];
            if ($event->rrule !== null) {
                array_push($dates, ...$event->rrule->getExDates(), ...$event->rrule->getExtraDates());
            }
            foreach ($dates as $date) {
                $name = $date !== null ? $this->namedTimezone($date) : null;
                if ($date === null || $name === null) {
                    continue;
                }
                $year          = (int) $date->format('Y');
                $zones[$name] ??= [$year, $year];
                $zones[$name]   = [min($zones[$name][0], $year), max($zones[$name][1], $year)];
            }
        };
        foreach ($this->events as $event) {
            $visit($event);
        }

        // Cover recurring series a few years ahead of today
        $horizon = (int) date('Y') + 5;
        foreach ($zones as $name => [$from, $to]) {
            $zones[$name] = [$from - 1, max($to, $horizon)];
        }
        return $zones;
    }

    /**
     * VTIMEZONE component built from PHP's tz database transitions.
     *
     * @return list<string>
     */
    private function buildTimezoneLines(DateTimeZone $tz, int $fromYear, int $toYear): array
    {
        $start       = (new DateTimeImmutable("{$fromYear}-01-01 00:00:00", new DateTimeZone('UTC')))->getTimestamp();
        $end         = (new DateTimeImmutable("{$toYear}-12-31 23:59:59", new DateTimeZone('UTC')))->getTimestamp();
        $transitions = $tz->getTransitions($start, $end) ?: [];

        $lines = ['BEGIN:VTIMEZONE', 'TZID:' . ICalFormatter::param($tz->getName())];

        $previousOffset = null;
        foreach ($transitions as $i => $transition) {
            $offset         = (int) $transition['offset'];
            $offsetFrom     = $previousOffset ?? $offset;
            $previousOffset = $offset;
            // The first entry describes the state at $start, not an actual transition
            $localStart = (new DateTimeImmutable('@' . ((int) $transition['ts'] + $offsetFrom)))->format('Ymd\THis');
            $kind       = $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD';

            $lines[] = 'BEGIN:' . $kind;
            $lines[] = 'DTSTART:' . ($i === 0 ? sprintf('%04d0101T000000', $fromYear) : $localStart);
            $lines[] = 'TZOFFSETFROM:' . self::formatOffset($offsetFrom);
            $lines[] = 'TZOFFSETTO:' . self::formatOffset($offset);
            $lines[] = 'TZNAME:' . ICalFormatter::text((string) $transition['abbr']);
            $lines[] = 'END:' . $kind;
        }

        if ($transitions === []) {
            $offset  = $tz->getOffset(new DateTimeImmutable('@' . $start));
            array_push(
                $lines,
                'BEGIN:STANDARD',
                sprintf('DTSTART:%04d0101T000000', $fromYear),
                'TZOFFSETFROM:' . self::formatOffset($offset),
                'TZOFFSETTO:' . self::formatOffset($offset),
                'END:STANDARD',
            );
        }

        $lines[] = 'END:VTIMEZONE';
        return $lines;
    }

    private static function formatOffset(int $seconds): string
    {
        $sign    = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);
        $out     = sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        return $seconds % 60 !== 0 ? $out . sprintf('%02d', $seconds % 60) : $out;
    }

    /**
     * A midnight EXDATE/RDATE on a timed series denotes the whole day — write it with the
     * series' start time so it matches the occurrence it refers to.
     */
    private function resolveDate(DateTimeImmutable $date, ICalEvent $event): DateTimeImmutable
    {
        if ($event->allDay || $date->format('H:i:s') !== '00:00:00' || $event->dtStart->format('H:i:s') === '00:00:00') {
            return $date;
        }
        return $event->dtStart->setDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
    }

    /**
     * @param  list<DateTimeImmutable> $dates
     * @return list<DateTimeImmutable>
     */
    private function uniqueDates(array $dates): array
    {
        $unique = [];
        foreach ($dates as $date) {
            $unique[$date->format('Y-m-d\TH:i:sP')] = $date;
        }
        return array_values($unique);
    }

    private function withRecurrenceId(ICalEvent $override, ICalEvent $master, string $key): ICalEvent
    {
        $original = DateTimeImmutable::createFromFormat('!Y-m-d', $key, $master->dtStart->getTimezone());
        if ($original === false) {
            return $override;
        }
        $original = $master->dtStart->setDate((int) $original->format('Y'), (int) $original->format('n'), (int) $original->format('j'));

        return new ICalEvent(
            uid:                 $master->uid,
            dtStart:             $override->dtStart,
            dtEnd:               $override->dtEnd,
            summary:             $override->summary,
            description:         $override->description,
            location:            $override->location,
            rrule:               null,
            url:                 $override->url,
            color:               $override->color,
            categories:          $override->categories,
            status:              $override->status,
            alarms:              $override->alarms,
            organizer:           $override->organizer,
            organizerName:       $override->organizerName,
            attendees:           $override->attendees,
            extensionProperties: $override->extensionProperties,
            transp:              $override->transp,
            classification:      $override->classification,
            priority:            $override->priority,
            dtStamp:             $override->dtStamp,
            created:             $override->created,
            lastModified:        $override->lastModified,
            sequence:            $override->sequence,
            recurrenceId:        $original,
            allDay:              $override->allDay,
            thisAndFuture:       $override->thisAndFuture,
        );
    }

    private function generateUid(): string
    {
        return sprintf('%s@php-calendar', bin2hex(random_bytes(16)));
    }
}
