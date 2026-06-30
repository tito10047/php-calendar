<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use DateTimeZone;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Minimal RFC 5545 iCal parser.
 *
 * Handles: VEVENT, RRULE, EXDATE, DTSTART, DTEND, DURATION, SUMMARY,
 * DESCRIPTION, LOCATION, UID.
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
     * @return list<ICalEvent>
     */
    public function parseString(string $icsContent): array
    {
        $lines  = $this->unfold($icsContent);
        $tzMap  = $this->parseTzMap($lines);
        $events = [];

        $inEvent    = false;
        $properties = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");

            if ($line === 'BEGIN:VEVENT') {
                $inEvent    = true;
                $properties = [];
                continue;
            }

            if ($line === 'END:VEVENT') {
                $inEvent = false;
                $event   = $this->buildEvent($properties, $tzMap);
                if ($event !== null) {
                    $events[] = $event;
                }
                continue;
            }

            if ($inEvent) {
                [$name, $params, $value] = $this->parseLine($line);
                $properties[$name][] = ['params' => $params, 'value' => $value];
            }
        }

        return $events;
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
        $content = str_replace(["\r\n ", "\r\n\t"], '', $content);
        return explode("\r\n", $content) ?: explode("\n", $content);
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
     * @param array<string, DateTimeZone> $tzMap
     */
    private function buildEvent(array $props, array $tzMap): ?ICalEvent
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
                $rrule = $rule;
            } catch (\InvalidArgumentException) {
                // Malformed RRULE — treat as non-recurring
            }
        }

        return new ICalEvent(
            uid: $uid,
            dtStart: $dtStart,
            dtEnd: $dtEnd,
            summary: $this->firstValue($props, 'SUMMARY'),
            description: $this->firstValue($props, 'DESCRIPTION'),
            location: $this->firstValue($props, 'LOCATION'),
            rrule: $rrule,
            exDates: $exDates,
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

        // Date-only: 20241101
        if (preg_match('/^\d{8}$/', $value)) {
            return DateTimeImmutable::createFromFormat('Ymd', $value, new DateTimeZone('UTC')) ?: null;
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
     */
    private function firstValue(array $props, string $name): ?string
    {
        return isset($props[$name]) ? $props[$name][0]['value'] : null;
    }
}
