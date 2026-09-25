<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use DateTimeZone;
use Tito10047\Calendar\Enum\EventClass;
use Tito10047\Calendar\Enum\EventStatus;
use Tito10047\Calendar\Enum\EventTransp;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Minimal RFC 5545 iCal parser.
 *
 * Handles: VEVENT, RRULE, EXDATE, DTSTART, DTEND, DURATION, SUMMARY,
 * DESCRIPTION, LOCATION, URL, UID.
 * Timezone handling: TZID property on DTSTART/DTEND, VTIMEZONE blocks (UTC offset),
 * and UTC Z-suffix dates.
 *
 * Usage:
 *   $parser = new ICalParser();
 *   $events = $parser->parseFile('/path/to/calendar.ics');
 *   $events = $parser->parseString($icsContent);
 *   $events = $parser->parseUrl('https://example.com/feed.ics');
 */
final class ICalParser
{
    /**
     * @return list<ICalEvent>
     */
    public function parseFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read iCal file: {$path}");
        }
        return $this->parseString($content);
    }

    /**
     * @return list<ICalEvent>
     */
    public function parseUrl(string $url): array
    {
        $context = stream_context_create([
            'http' => ['timeout' => 10, 'user_agent' => 'php-calendar/2.0 iCalParser'],
            'ssl'  => ['verify_peer' => true],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new \RuntimeException("Cannot fetch iCal URL: {$url}");
        }
        return $this->parseString($content);
    }

    /**
     * @return list<ICalTodo>
     */
    public function parseTodos(string $icsContent): array
    {
        $lines = $this->unfold($icsContent);
        $tzMap = $this->parseTzMap($lines);
        $todos = [];

        $inTodo     = false;
        $properties = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if ($line === 'BEGIN:VTODO') {
                $inTodo     = true;
                $properties = [];
                continue;
            }

            if ($line === 'END:VTODO') {
                $inTodo = false;
                $todos[] = $this->buildTodo($properties, $tzMap);
                continue;
            }

            if ($inTodo) {
                [$name, $params, $value] = $this->parseLine($line);
                $properties[$name][] = ['params' => $params, 'value' => $value];
            }
        }

        return $todos;
    }

    /**
     * @return list<ICalEvent>
     */
    public function parseString(string $icsContent): array
    {
        $lines  = $this->unfold($icsContent);
        $tzMap  = $this->parseTzMap($lines);

        /** @var list<ICalEvent> $masters */
        $masters   = [];
        /** @var list<ICalEvent> $overrides */
        $overrides = [];

        $inEvent    = false;
        $inAlarm    = false;
        $properties = [];
        $alarmProps = [];
        /** @var list<VAlarm> $alarms */
        $alarms     = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if ($line === 'BEGIN:VEVENT') {
                $inEvent    = true;
                $properties = [];
                $alarms     = [];
                continue;
            }

            if ($line === 'END:VEVENT') {
                $inEvent = false;
                $event   = $this->buildEvent($properties, $tzMap, $alarms);
                if ($event !== null) {
                    if ($event->recurrenceId !== null) {
                        $overrides[] = $event;
                    } else {
                        $masters[] = $event;
                    }
                }
                continue;
            }

            if ($inEvent && $line === 'BEGIN:VALARM') {
                $inAlarm    = true;
                $alarmProps = [];
                continue;
            }

            if ($inEvent && $line === 'END:VALARM') {
                $inAlarm = false;
                $alarm   = $this->buildAlarm($alarmProps);
                if ($alarm !== null) {
                    $alarms[] = $alarm;
                }
                continue;
            }

            if ($inAlarm) {
                [$name, $params, $value] = $this->parseLine($line);
                $alarmProps[$name][] = ['params' => $params, 'value' => $value];
            } elseif ($inEvent) {
                [$name, $params, $value] = $this->parseLine($line);
                $properties[$name][] = ['params' => $params, 'value' => $value];
            }
        }

        // Attach RECURRENCE-ID overrides to their master events
        foreach ($overrides as $override) {
            foreach ($masters as $i => $master) {
                if ($master->uid === $override->uid && $override->recurrenceId !== null) {
                    $masters[$i] = $master->withModifiedOccurrence($override->recurrenceId, $override);
                    break;
                }
            }
        }

        return array_values($masters);
    }

    // -------------------------------------------------------------------------
    // Internal parsing
    // -------------------------------------------------------------------------

    /**
     * Unfold RFC 5545 line continuations (CRLF followed by whitespace).
     *
     * @return list<string>
     */
    private function unfold(string $content): array
    {
        if (!str_contains($content, "\r\n") && str_contains($content, "\n")) {
            $content = str_replace("\n", "\r\n", $content);
        }
        $content = str_replace(["\r\n ", "\r\n\t"], '', $content);
        return explode("\r\n", $content);
    }

    /**
     * Parse VTIMEZONE blocks and build a tzid → DateTimeZone map.
     *
     * @param  array<string>           $lines
     * @return array<string, DateTimeZone>
     */
    private function parseTzMap(array $lines): array
    {
        $map       = [];
        $inTz      = false;
        $tzId      = null;
        $offsetStr = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === 'BEGIN:VTIMEZONE') {
                $inTz      = true;
                $tzId      = null;
                $offsetStr = null;
                continue;
            }
            if ($line === 'END:VTIMEZONE') {
                $inTz = false;
                if ($tzId !== null) {
                    try {
                        $map[$tzId] = new DateTimeZone($tzId);
                    } catch (\Exception) {
                        // Fall back to UTC offset if TZID is not a named timezone
                        if ($offsetStr !== null) {
                            try {
                                $map[$tzId] = new DateTimeZone($offsetStr);
                            } catch (\Exception) {
                                $map[$tzId] = new DateTimeZone('UTC');
                            }
                        } else {
                            $map[$tzId] = new DateTimeZone('UTC');
                        }
                    }
                }
                continue;
            }
            if ($inTz) {
                [$name, , $value] = $this->parseLine($line);
                if ($name === 'TZID') {
                    $tzId = $value;
                }
                if ($name === 'TZOFFSETTO') {
                    $offsetStr = $this->normaliseOffset($value);
                }
            }
        }

        return $map;
    }

    /**
     * @return array{string, array<string,string>, string}
     */
    private function parseLine(string $line): array
    {
        $colonPos = strpos($line, ':');
        if ($colonPos === false) {
            return [$line, [], ''];
        }

        $namePart = substr($line, 0, $colonPos);
        $value    = substr($line, $colonPos + 1);

        // Split name and parameters (e.g. DTSTART;TZID=America/New_York)
        $parts  = explode(';', $namePart);
        $name   = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $param) {
            $eqPos = strpos($param, '=');
            if ($eqPos !== false) {
                $params[strtoupper(substr($param, 0, $eqPos))] = substr($param, $eqPos + 1);
            }
        }

        return [$name, $params, $value];
    }

    /**
     * @param array<string, list<array{params: array<string,string>, value: string}>> $props
     * @param array<string, DateTimeZone>                                             $tzMap
     * @param list<VAlarm>                                                            $alarms
     */
    private function buildEvent(array $props, array $tzMap, array $alarms = []): ?ICalEvent
    {
        $uid = $this->firstValue($props, 'UID') ?? uniqid('event_', true);

        $dtStartEntry = $props['DTSTART'][0] ?? null;
        if ($dtStartEntry === null) {
            return null;
        }
        $dtStart = $this->parseDateTime($dtStartEntry['value'], $dtStartEntry['params'], $tzMap);
        if ($dtStart === null) {
            return null;
        }

        // An event is all-day when its DTSTART is a DATE value — either declared
        // (VALUE=DATE) or recognisable by shape. Clients disagree on sending the
        // parameter; the shape never lies.
        $allDay = strtoupper($dtStartEntry['params']['VALUE'] ?? '') === 'DATE'
            || preg_match('/^\d{8}$/', trim($dtStartEntry['value'])) === 1;

        $dtEnd = null;
        if (isset($props['DTEND'])) {
            $e = $props['DTEND'][0];
            $dtEnd = $this->parseDateTime($e['value'], $e['params'], $tzMap);
        } elseif (isset($props['DURATION'])) {
            $duration = $this->parseDuration($props['DURATION'][0]['value']);
            $dtEnd    = $duration !== null ? $dtStart->add($duration) : null;
        }

        $rrule   = null;
        $exDates = [];

        if (isset($props['RRULE'])) {
            try {
                $rule = RecurrenceRule::fromRrule($props['RRULE'][0]['value']);
                // Incorporate EXDATE entries directly into the rule
                if (isset($props['EXDATE'])) {
                    foreach ($props['EXDATE'] as $exEntry) {
                        $exDate = $this->parseDateTime($exEntry['value'], $exEntry['params'], $tzMap);
                        if ($exDate !== null) {
                            $exDates[] = $exDate;
                        }
                    }
                }
                // Incorporate RDATE explicit occurrence dates
                if (isset($props['RDATE'])) {
                    $rDates = [];
                    foreach ($props['RDATE'] as $rdEntry) {
                        foreach (explode(',', $rdEntry['value']) as $rawDate) {
                            $rawDate = trim($rawDate);
                            if ($rawDate === '') {
                                continue;
                            }
                            $rDate = $this->parseDateTime($rawDate, $rdEntry['params'], $tzMap);
                            if ($rDate !== null) {
                                $rDates[] = $rDate;
                            }
                        }
                    }
                    if ($rDates !== []) {
                        $rule = $rule->withExtraDates(...$rDates);
                    }
                }
                $rrule = $rule;
            } catch (\InvalidArgumentException) {
                // Malformed RRULE — treat as non-recurring
            }
        }

        $categories = [];
        if (isset($props['CATEGORIES'])) {
            foreach ($props['CATEGORIES'] as $catEntry) {
                foreach (preg_split('/(?<!\\\\),/', $catEntry['value']) ?: [] as $cat) {
                    $cat = (string) $this->unescapeText($cat);
                    $cat = trim($cat);
                    if ($cat !== '') {
                        $categories[] = $cat;
                    }
                }
            }
        }

        $statusRaw = $this->firstValue($props, 'STATUS');
        $status    = $statusRaw !== null ? EventStatus::tryFrom(strtoupper($statusRaw)) : null;

        $organizerEmail = null;
        $organizerName  = null;
        if (isset($props['ORGANIZER'])) {
            $org   = $props['ORGANIZER'][0];
            $val   = $org['value'];
            $organizerEmail = str_starts_with(strtolower($val), 'mailto:') ? substr($val, 7) : $val;
            $organizerName  = $org['params']['CN'] ?? null;
        }

        $attendees = [];
        if (isset($props['ATTENDEE'])) {
            foreach ($props['ATTENDEE'] as $att) {
                $val      = $att['value'];
                $email    = str_starts_with(strtolower($val), 'mailto:') ? substr($val, 7) : $val;
                $name     = $att['params']['CN'] ?? null;
                $role     = $att['params']['ROLE'] ?? 'REQ-PARTICIPANT';
                $partStat = $att['params']['PARTSTAT'] ?? 'NEEDS-ACTION';
                $rsvp     = strtoupper($att['params']['RSVP'] ?? '') === 'TRUE';
                $attendees[] = new Attendee($email, $name, $role, $partStat, $rsvp);
            }
        }

        // X-* extension properties
        $extensionProperties = [];
        foreach ($props as $propName => $entries) {
            if (str_starts_with($propName, 'X-')) {
                $extensionProperties[$propName] = $entries[0]['value'];
            }
        }

        // TRANSP
        $transpRaw = $this->firstValue($props, 'TRANSP');
        $transp    = $transpRaw !== null ? EventTransp::tryFrom(strtoupper($transpRaw)) : null;

        // CLASS
        $classRaw       = $this->firstValue($props, 'CLASS');
        $classification = $classRaw !== null ? EventClass::tryFrom(strtoupper($classRaw)) : null;

        // CalDAV metadata
        $dtStampEntry    = $props['DTSTAMP'][0] ?? null;
        $createdEntry    = $props['CREATED'][0] ?? null;
        $lastModEntry    = $props['LAST-MODIFIED'][0] ?? null;
        $recurrenceEntry = $props['RECURRENCE-ID'][0] ?? null;

        return new ICalEvent(
            uid:                 $uid,
            dtStart:             $dtStart,
            dtEnd:               $dtEnd,
            summary:             $this->unescapeText($this->firstValue($props, 'SUMMARY')),
            description:         $this->unescapeText($this->firstValue($props, 'DESCRIPTION')),
            location:            $this->unescapeText($this->firstValue($props, 'LOCATION')),
            rrule:               $rrule,
            exDates:             $exDates,
            url:                 $this->firstValue($props, 'URL'),
            color:               $this->firstValue($props, 'COLOR'),
            categories:          $categories,
            status:              $status,
            alarms:              $alarms,
            organizer:           $organizerEmail,
            organizerName:       $organizerName,
            attendees:           $attendees,
            extensionProperties: $extensionProperties,
            transp:              $transp,
            classification:      $classification,
            priority:            (int) ($this->firstValue($props, 'PRIORITY') ?? 0),
            dtStamp:             $dtStampEntry !== null ? $this->parseDateTime($dtStampEntry['value'], $dtStampEntry['params'], $tzMap) : null,
            created:             $createdEntry !== null ? $this->parseDateTime($createdEntry['value'], $createdEntry['params'], $tzMap) : null,
            lastModified:        $lastModEntry !== null ? $this->parseDateTime($lastModEntry['value'], $lastModEntry['params'], $tzMap) : null,
            sequence:            (int) ($this->firstValue($props, 'SEQUENCE') ?? 0),
            recurrenceId:        $recurrenceEntry !== null ? $this->parseDateTime($recurrenceEntry['value'], $recurrenceEntry['params'], $tzMap) : null,
            allDay:              $allDay,
        );
    }

    /**
     * Parse an iCal date/datetime string (value + params) into DateTimeImmutable.
     *
     * @param array<string, string> $params
     * @param array<string, DateTimeZone> $tzMap
     */
    private function parseDateTime(string $value, array $params, array $tzMap): ?DateTimeImmutable
    {
        $value = trim($value);

        // Date-only: 20241101 — the leading "!" resets the time, otherwise
        // createFromFormat() fills it from the current clock and a date-only
        // value silently carries the hour the import happened to run at.
        if (preg_match('/^\d{8}$/', $value)) {
            return DateTimeImmutable::createFromFormat('!Ymd', $value, new DateTimeZone('UTC')) ?: null;
        }

        // DateTime with Z suffix (UTC): 20241101T120000Z
        if (str_ends_with($value, 'Z')) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        // DateTime with TZID param: 20241101T120000
        $tzId = $params['TZID'] ?? null;
        $tz   = $tzId !== null
            ? ($tzMap[$tzId] ?? $this->tryNamedTz($tzId))
            : new DateTimeZone('UTC');

        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
        return $dt ?: null;
    }

    private function parseDuration(string $value): ?\DateInterval
    {
        try {
            return new \DateInterval($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function normaliseOffset(string $offset): string
    {
        // +0100 → +01:00
        if (preg_match('/^([+-])(\d{2})(\d{2})$/', $offset, $m)) {
            return "{$m[1]}{$m[2]}:{$m[3]}";
        }
        return $offset;
    }

    private function tryNamedTz(string $tzId): DateTimeZone
    {
        try {
            return new DateTimeZone($tzId);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, list<array{params: array<string,string>, value: string}>> $props
     * @param array<string, DateTimeZone>                                             $tzMap
     */
    private function buildTodo(array $props, array $tzMap): ICalTodo
    {
        $uid = $this->firstValue($props, 'UID') ?? uniqid('todo_', true);

        $due     = null;
        $dtStart = null;
        if (isset($props['DUE'])) {
            $e   = $props['DUE'][0];
            $due = $this->parseDateTime($e['value'], $e['params'], $tzMap);
        }
        if (isset($props['DTSTART'])) {
            $e       = $props['DTSTART'][0];
            $dtStart = $this->parseDateTime($e['value'], $e['params'], $tzMap);
        }

        return new ICalTodo(
            uid:             $uid,
            summary:         $this->unescapeText($this->firstValue($props, 'SUMMARY')),
            description:     $this->unescapeText($this->firstValue($props, 'DESCRIPTION')),
            due:             $due,
            dtStart:         $dtStart,
            status:          $this->firstValue($props, 'STATUS') ?? 'NEEDS-ACTION',
            priority:        (int) ($this->firstValue($props, 'PRIORITY') ?? 0),
            percentComplete: (int) ($this->firstValue($props, 'PERCENT-COMPLETE') ?? 0),
        );
    }

    /**
     * @param array<string, list<array{params: array<string,string>, value: string}>> $props
     */
    private function buildAlarm(array $props): ?VAlarm
    {
        $action  = $this->firstValue($props, 'ACTION');
        $trigger = $this->firstValue($props, 'TRIGGER');
        if ($action === null || $trigger === null) {
            return null;
        }
        return new VAlarm(
            action:      strtoupper($action),
            trigger:     $trigger,
            description: $this->unescapeText($this->firstValue($props, 'DESCRIPTION')),
            summary:     $this->unescapeText($this->firstValue($props, 'SUMMARY')),
        );
    }

    /**
     * @param array<string, list<array{params: array<string,string>, value: string}>> $props
     */
    private function firstValue(array $props, string $name): ?string
    {
        return isset($props[$name]) ? $props[$name][0]['value'] : null;
    }

    /**
     * Undo RFC 5545 §3.3.11 text escaping.
     *
     * A DESCRIPTION arrives as one line with \n where the newlines were, and
     * with commas and semicolons backslashed. Handing that through unchanged
     * means a note comes back with visible backslashes and a line break that
     * never happens — and anything reading the first line of a description
     * reads the whole thing instead.
     */
    private function unescapeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace_callback(
            '/\\\\(.)/',
            static fn (array $match): string => match ($match[1]) {
                'n', 'N' => "\n",
                default => $match[1],
            },
            $value,
        ) ?? $value;
    }
}
