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
 * RFC 5545 iCal parser.
 *
 * Handles: VEVENT (incl. RECURRENCE-ID overrides and RANGE=THISANDFUTURE), VTODO, VALARM,
 * VTIMEZONE, RRULE, RDATE, EXDATE (incl. comma-separated lists), DTSTART/DTEND/DURATION,
 * DATE vs. DATE-TIME values, TEXT escaping, quoted parameter values and RFC 6868 parameter
 * encoding.
 *
 * Timezones: TZID parameters resolve against the file's VTIMEZONE blocks and IANA names;
 * UTC values keep the Z suffix; floating times and DATE values are created in the default
 * timezone (constructor argument, falls back to date_default_timezone_get()).
 *
 * Usage:
 *   $parser = new ICalParser();
 *   $events = $parser->parseFile('/path/to/calendar.ics');
 *   $events = $parser->parseString($icsContent);
 *   $events = $parser->parseUrl('https://example.com/feed.ics');
 */
final class ICalParser
{
    /** Default size limit for parseUrl() / parseFile() — 10 MiB. */
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    private const TEXT_PROPERTIES = ['SUMMARY', 'DESCRIPTION', 'LOCATION', 'COMMENT', 'UID', 'COLOR', 'CONTACT', 'RESOURCES'];

    private readonly DateTimeZone $defaultTimezone;

    /**
     * @param positive-int      $maxBytes        size limit for parseUrl() / parseFile()
     * @param int               $timeout         HTTP timeout in seconds for parseUrl()
     * @param DateTimeZone|null $defaultTimezone zone for floating times and DATE values
     */
    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $timeout = 10,
        ?DateTimeZone $defaultTimezone = null,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be positive');
        }
        $this->defaultTimezone = $defaultTimezone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * Parse a local .ics file. Only pass trusted paths — for remote feeds use parseUrl().
     *
     * @return list<ICalEvent>
     */
    public function parseFile(string $path): array
    {
        $content = @file_get_contents($path, false, null, 0, $this->maxBytes + 1);
        if ($content === false) {
            throw new \RuntimeException("Cannot read iCal file: {$path}");
        }
        $this->assertSize($content);
        return $this->parseString($content);
    }

    /**
     * Fetch and parse a remote feed. Only http:// and https:// URLs are accepted (webcal:// is
     * rewritten to https://); the response is limited to maxBytes and must have a 2xx status.
     *
     * @return list<ICalEvent>
     */
    public function parseUrl(string $url): array
    {
        return $this->parseString($this->fetch($url));
    }

    /**
     * @return list<ICalTodo>
     */
    public function parseTodos(string $icsContent): array
    {
        $root  = $this->parseComponents($icsContent);
        $tzMap = $this->parseTzMap($root);
        $todos = [];

        foreach ($root->find('VTODO') as $todo) {
            $todos[] = $this->buildTodo($todo->props, $tzMap);
        }

        return $todos;
    }

    /**
     * @return list<ICalEvent>
     */
    public function parseString(string $icsContent): array
    {
        $root  = $this->parseComponents($icsContent);
        $tzMap = $this->parseTzMap($root);

        /** @var list<ICalEvent> $masters */
        $masters = [];
        /** @var list<ICalEvent> $overrides */
        $overrides = [];

        foreach ($root->find('VEVENT') as $component) {
            $alarms = [];
            foreach ($component->children as $child) {
                if ($child->name === 'VALARM') {
                    $alarm = $this->buildAlarm($child->props);
                    if ($alarm !== null) {
                        $alarms[] = $alarm;
                    }
                }
            }

            $event = $this->buildEvent($component->props, $tzMap, $alarms);
            if ($event === null) {
                continue;
            }
            if ($event->recurrenceId !== null) {
                $overrides[] = $event;
            } else {
                $masters[] = $event;
            }
        }

        // Attach RECURRENCE-ID overrides to their master events; orphans (e.g. a single-instance
        // invitation) are standalone instances and are returned as regular events.
        foreach ($overrides as $override) {
            $attached = false;
            foreach ($masters as $i => $master) {
                if ($master->uid === $override->uid && $master->isRecurring() && $override->recurrenceId !== null) {
                    $masters[$i] = $master->withModifiedOccurrence($override->recurrenceId, $override);
                    $attached    = true;
                    break;
                }
            }
            if (!$attached) {
                $masters[] = $override;
            }
        }

        return array_values($masters);
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    private function fetch(string $url): string
    {
        if (preg_match('#^webcals?://#i', $url)) {
            $url = 'https://' . substr($url, strpos($url, '://') + 3);
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            throw new \InvalidArgumentException('Only http:// and https:// iCal URLs are supported, got: ' . self::redactUrl($url));
        }

        $context = stream_context_create([
            'http' => [
                'timeout'         => $this->timeout,
                'user_agent'      => 'php-calendar iCalParser',
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $content = @file_get_contents($url, false, $context, 0, $this->maxBytes + 1);
        if ($content === false) {
            throw new \RuntimeException('Cannot fetch iCal URL: ' . self::redactUrl($url));
        }

        // $http_response_header is deprecated as of PHP 8.5; http_get_last_response_headers() exists since 8.4
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : $http_response_header;
        $status  = self::lastStatusCode(is_array($headers) ? $headers : []);
        if ($status !== null && ($status < 200 || $status >= 300)) {
            throw new \RuntimeException("Cannot fetch iCal URL (HTTP {$status}): " . self::redactUrl($url));
        }

        $this->assertSize($content);

        return $content;
    }

    private function assertSize(string $content): void
    {
        if (strlen($content) > $this->maxBytes) {
            throw new \RuntimeException("iCal content exceeds the {$this->maxBytes} byte limit");
        }
    }

    /** @param array<mixed> $headers */
    private static function lastStatusCode(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /** Remove user:password@ from a URL so credentials never end up in exception messages. */
    private static function redactUrl(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@]*@#i', '$1***@', $url);
    }

    // -------------------------------------------------------------------------
    // Lexing
    // -------------------------------------------------------------------------

    /**
     * Unfold RFC 5545 line continuations and split into content lines.
     *
     * @return list<string>
     */
    private function unfold(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = str_replace(["\n ", "\n\t"], '', $content);

        return array_values(array_filter(explode("\n", $content), static fn (string $l) => trim($l) !== ''));
    }

    /** Build the component tree; unterminated components are discarded. */
    private function parseComponents(string $content): ICalComponent
    {
        $root = new ICalComponent('ROOT');
        /** @var non-empty-list<ICalComponent> $stack */
        $stack = [$root];

        foreach ($this->unfold($content) as $line) {
            [$name, $params, $value] = $this->parseLine($line);

            if ($name === 'BEGIN') {
                $stack[] = new ICalComponent(strtoupper(trim($value)));
                continue;
            }

            if ($name === 'END') {
                $endName = strtoupper(trim($value));
                // Close up to the matching component; tolerate missing END lines of nested ones
                for ($i = count($stack) - 1; $i > 0; $i--) {
                    if ($stack[$i]->name === $endName) {
                        while (count($stack) > $i) {
                            $done                               = array_pop($stack);
                            $stack[count($stack) - 1]->children[] = $done;
                        }
                        break;
                    }
                }
                continue;
            }

            if ($name !== '') {
                $stack[count($stack) - 1]->addProperty($name, $params, $value);
            }
        }

        return $root;
    }

    /**
     * Split a content line into name, parameters and value (RFC 5545 §3.1).
     * Parameter values may be DQUOTE-quoted and contain ';', ':' and ','. Repeated parameters
     * are merged into a comma-separated list. RFC 6868 ^-escapes are decoded.
     *
     * @return array{string, array<string, string>, string}
     */
    private function parseLine(string $line): array
    {
        $len = strlen($line);
        $pos = strcspn($line, ';:');
        $name = strtoupper(trim(substr($line, 0, $pos)));
        $params = [];

        while ($pos < $len && $line[$pos] === ';') {
            $pos++;
            $eq        = strcspn($line, '=;:', $pos);
            $paramName = strtoupper(trim(substr($line, $pos, $eq)));
            $pos      += $eq;

            $values = [];
            if ($pos < $len && $line[$pos] === '=') {
                do {
                    $pos++;
                    if ($pos < $len && $line[$pos] === '"') {
                        $close    = strpos($line, '"', $pos + 1);
                        $close    = $close === false ? $len : $close;
                        $values[] = substr($line, $pos + 1, $close - $pos - 1);
                        $pos      = min($len, $close + 1);
                    } else {
                        $n        = strcspn($line, ',;:', $pos);
                        $values[] = substr($line, $pos, $n);
                        $pos     += $n;
                    }
                } while ($pos < $len && $line[$pos] === ',');
            }

            if ($paramName !== '') {
                $decoded           = array_map(self::decodeParamValue(...), $values);
                $params[$paramName] = isset($params[$paramName])
                    ? $params[$paramName] . ',' . implode(',', $decoded)
                    : implode(',', $decoded);
            }
        }

        $value = $pos < $len && $line[$pos] === ':' ? substr($line, $pos + 1) : '';

        return [$name, $params, $value];
    }

    /** RFC 6868: ^n = newline, ^' = DQUOTE, ^^ = ^ */
    private static function decodeParamValue(string $value): string
    {
        return (string) preg_replace_callback(
            "/\\^([n'^])/",
            static fn (array $m) => match ($m[1]) {
                'n'     => "\n",
                "'"     => '"',
                default => '^',
            },
            $value,
        );
    }

    /** Undo RFC 5545 §3.3.11 TEXT escaping. */
    private static function unescapeText(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([\\\\;,nN])/',
            static fn (array $m) => ($m[1] === 'n' || $m[1] === 'N') ? "\n" : $m[1],
            $value,
        );
    }

    /**
     * Split a TEXT list on unescaped commas and unescape each item.
     *
     * @return list<string>
     */
    private static function splitTextList(string $value): array
    {
        $items   = [];
        $current = '';
        $len     = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $i + 1 < $len) {
                $current .= $char . $value[++$i];
                continue;
            }
            if ($char === ',') {
                $items[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $items[] = $current;

        return array_values(array_filter(
            array_map(static fn (string $item) => trim(self::unescapeText($item)), $items),
            static fn (string $item) => $item !== '',
        ));
    }

    // -------------------------------------------------------------------------
    // Timezones
    // -------------------------------------------------------------------------

    /**
     * Build a TZID → DateTimeZone map from the VTIMEZONE components.
     *
     * @return array<string, DateTimeZone>
     */
    private function parseTzMap(ICalComponent $root): array
    {
        $map = [];

        foreach ($root->find('VTIMEZONE') as $vtz) {
            $tzId = $this->firstValue($vtz->props, 'TZID');
            if ($tzId === null) {
                continue;
            }

            $named = $this->tryNamedTz($tzId) ?? $this->tryNamedTz($this->firstValue($vtz->props, 'X-LIC-LOCATION') ?? '');
            if ($named !== null) {
                $map[$tzId] = $named;
                continue;
            }

            // Not a known zone name — fall back to a fixed offset (prefer STANDARD time)
            $offsets = ['STANDARD' => null, 'DAYLIGHT' => null];
            foreach ($vtz->children as $sub) {
                if (array_key_exists($sub->name, $offsets)) {
                    $offsets[$sub->name] = $this->firstValue($sub->props, 'TZOFFSETTO');
                }
            }
            $offset = $offsets['STANDARD'] ?? $offsets['DAYLIGHT'];
            try {
                $map[$tzId] = $offset !== null ? new DateTimeZone($this->normaliseOffset($offset)) : new DateTimeZone('UTC');
            } catch (\Exception) {
                $map[$tzId] = new DateTimeZone('UTC');
            }
        }

        return $map;
    }

    private function normaliseOffset(string $offset): string
    {
        // +0100 → +01:00, +010000 → +01:00
        if (preg_match('/^([+-])(\d{2})(\d{2})(\d{2})?$/', trim($offset), $m)) {
            return "{$m[1]}{$m[2]}:{$m[3]}";
        }
        return trim($offset);
    }

    private function tryNamedTz(string $tzId): ?DateTimeZone
    {
        $tzId = trim($tzId);
        if ($tzId === '') {
            return null;
        }
        // Global TZIDs may be prefixed with "/" (RFC 5545 §3.2.19)
        foreach ([$tzId, ltrim($tzId, '/')] as $candidate) {
            try {
                return new DateTimeZone($candidate);
            } catch (\Exception) {
                // try next
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Values
    // -------------------------------------------------------------------------

    /**
     * Parse a DATE or DATE-TIME value.
     *
     * @param  array<string, string>        $params
     * @param  array<string, DateTimeZone>  $tzMap
     * @return array{DateTimeImmutable, bool}|null [value, isDate]
     */
    private function parseDateValue(string $value, array $params, array $tzMap): ?array
    {
        $value = strtoupper(trim($value));

        // DATE: 20241101 — a calendar date, created at midnight in the default timezone
        if (preg_match('/^\d{8}$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('!Ymd', $value, $this->defaultTimezone);
            return $dt !== false ? [$dt, true] : null;
        }

        // DATE-TIME in UTC: 20241101T120000Z
        if (preg_match('/^\d{8}T\d{6}Z$/', $value)) {
            $dt = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $value, new DateTimeZone('UTC'));
            return $dt !== false ? [$dt, false] : null;
        }

        // DATE-TIME with TZID, or floating
        if (preg_match('/^\d{8}T\d{6}$/', $value)) {
            $tzId = $params['TZID'] ?? null;
            $tz   = $tzId !== null
                ? ($tzMap[$tzId] ?? $this->tryNamedTz($tzId) ?? $this->defaultTimezone)
                : $this->defaultTimezone;
            $dt = DateTimeImmutable::createFromFormat('!Ymd\THis', $value, $tz);
            return $dt !== false ? [$dt, false] : null;
        }

        return null;
    }

    /**
     * @param array<string, string>       $params
     * @param array<string, DateTimeZone> $tzMap
     */
    private function parseDateTime(string $value, array $params, array $tzMap): ?DateTimeImmutable
    {
        return $this->parseDateValue($value, $params, $tzMap)[0] ?? null;
    }

    /**
     * Parse every date of a (possibly comma-separated, possibly multi-line) EXDATE/RDATE property.
     * PERIOD values contribute their start.
     *
     * @param  list<array{params: array<string, string>, value: string}> $entries
     * @param  array<string, DateTimeZone>                               $tzMap
     * @return list<DateTimeImmutable>
     */
    private function parseDateList(array $entries, array $tzMap): array
    {
        $dates = [];
        foreach ($entries as $entry) {
            foreach (explode(',', $entry['value']) as $raw) {
                $raw = trim(explode('/', $raw, 2)[0]);
                if ($raw === '') {
                    continue;
                }
                $dt = $this->parseDateTime($raw, $entry['params'], $tzMap);
                if ($dt !== null) {
                    $dates[] = $dt;
                }
            }
        }
        return $dates;
    }

    /** RFC 5545 DURATION (e.g. PT1H30M, P1D, P2W, -PT15M) → DateInterval. */
    private function parseDuration(string $value): ?\DateInterval
    {
        $value    = strtoupper(trim($value));
        $negative = str_starts_with($value, '-');
        $value    = ltrim($value, '+-');
        try {
            $interval         = new \DateInterval($value);
            $interval->invert = $negative ? 1 : 0;
            return $interval;
        } catch (\Exception) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Component builders
    // -------------------------------------------------------------------------

    /**
     * @param array<string, list<array{params: array<string, string>, value: string}>> $props
     * @param array<string, DateTimeZone>                                             $tzMap
     * @param list<VAlarm>                                                            $alarms
     */
    private function buildEvent(array $props, array $tzMap, array $alarms = []): ?ICalEvent
    {
        $uid = $this->textValue($props, 'UID') ?? uniqid('event_', true);

        $dtStartEntry = $props['DTSTART'][0] ?? null;
        if ($dtStartEntry === null) {
            return null;
        }
        $parsedStart = $this->parseDateValue($dtStartEntry['value'], $dtStartEntry['params'], $tzMap);
        if ($parsedStart === null) {
            return null;
        }
        [$dtStart, $allDay] = $parsedStart;

        $dtEnd = null;
        if (isset($props['DTEND'])) {
            $e     = $props['DTEND'][0];
            $dtEnd = $this->parseDateTime($e['value'], $e['params'], $tzMap);
        } elseif (isset($props['DURATION'])) {
            $duration = $this->parseDuration($props['DURATION'][0]['value']);
            $dtEnd    = $duration !== null ? $dtStart->add($duration) : null;
        }

        $exDates = isset($props['EXDATE']) ? $this->parseDateList($props['EXDATE'], $tzMap) : [];
        $rDates  = isset($props['RDATE']) ? $this->parseDateList($props['RDATE'], $tzMap) : [];

        $rrule = null;
        if (isset($props['RRULE'])) {
            try {
                $rrule = RecurrenceRule::fromRrule($props['RRULE'][0]['value']);
            } catch (\InvalidArgumentException) {
                // Malformed RRULE — treat as non-recurring rather than losing the whole feed
            }
        }
        if ($rrule === null && $rDates !== []) {
            // RDATE without RRULE: the recurrence set is DTSTART plus the explicit dates
            $rrule = RecurrenceRule::daily()->limitTo(1);
        }
        if ($rrule !== null && $rDates !== []) {
            $rrule = $rrule->withExtraDates(...$rDates);
        }

        $categories = [];
        foreach ($props['CATEGORIES'] ?? [] as $catEntry) {
            array_push($categories, ...self::splitTextList($catEntry['value']));
        }

        $statusRaw = $this->firstValue($props, 'STATUS');
        $status    = $statusRaw !== null ? EventStatus::tryFrom(strtoupper(trim($statusRaw))) : null;

        $organizerEmail = null;
        $organizerName  = null;
        if (isset($props['ORGANIZER'])) {
            $org            = $props['ORGANIZER'][0];
            $organizerEmail = self::stripMailto($org['value']);
            $organizerName  = $org['params']['CN'] ?? null;
        }

        $attendees = [];
        foreach ($props['ATTENDEE'] ?? [] as $att) {
            $attendees[] = new Attendee(
                email:    self::stripMailto($att['value']),
                name:     $att['params']['CN'] ?? null,
                role:     strtoupper($att['params']['ROLE'] ?? 'REQ-PARTICIPANT'),
                partStat: strtoupper($att['params']['PARTSTAT'] ?? 'NEEDS-ACTION'),
                rsvp:     strtoupper($att['params']['RSVP'] ?? '') === 'TRUE',
            );
        }

        // X-* extension properties (TEXT by default)
        $extensionProperties = [];
        foreach ($props as $propName => $entries) {
            if (str_starts_with($propName, 'X-')) {
                $extensionProperties[$propName] = self::unescapeText($entries[0]['value']);
            }
        }

        $transpRaw = $this->firstValue($props, 'TRANSP');
        $transp    = $transpRaw !== null ? EventTransp::tryFrom(strtoupper(trim($transpRaw))) : null;

        $classRaw       = $this->firstValue($props, 'CLASS');
        $classification = $classRaw !== null ? EventClass::tryFrom(strtoupper(trim($classRaw))) : null;

        $recurrenceEntry = $props['RECURRENCE-ID'][0] ?? null;

        return new ICalEvent(
            uid:                 $uid,
            dtStart:             $dtStart,
            dtEnd:               $dtEnd,
            summary:             $this->textValue($props, 'SUMMARY'),
            description:         $this->textValue($props, 'DESCRIPTION'),
            location:            $this->textValue($props, 'LOCATION'),
            rrule:               $rrule,
            exDates:             $exDates,
            url:                 $this->firstValue($props, 'URL'),
            color:               $this->textValue($props, 'COLOR'),
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
            dtStamp:             $this->dateProp($props, 'DTSTAMP', $tzMap),
            created:             $this->dateProp($props, 'CREATED', $tzMap),
            lastModified:        $this->dateProp($props, 'LAST-MODIFIED', $tzMap),
            sequence:            (int) ($this->firstValue($props, 'SEQUENCE') ?? 0),
            recurrenceId:        $this->dateProp($props, 'RECURRENCE-ID', $tzMap),
            allDay:              $allDay,
            thisAndFuture:       strtoupper($recurrenceEntry['params']['RANGE'] ?? '') === 'THISANDFUTURE',
        );
    }

    /**
     * @param array<string, list<array{params: array<string, string>, value: string}>> $props
     * @param array<string, DateTimeZone>                                             $tzMap
     */
    private function buildTodo(array $props, array $tzMap): ICalTodo
    {
        $uid = $this->textValue($props, 'UID') ?? uniqid('todo_', true);

        return new ICalTodo(
            uid:             $uid,
            summary:         $this->textValue($props, 'SUMMARY'),
            description:     $this->textValue($props, 'DESCRIPTION'),
            due:             $this->dateProp($props, 'DUE', $tzMap),
            dtStart:         $this->dateProp($props, 'DTSTART', $tzMap),
            status:          strtoupper(trim($this->firstValue($props, 'STATUS') ?? 'NEEDS-ACTION')),
            priority:        (int) ($this->firstValue($props, 'PRIORITY') ?? 0),
            percentComplete: (int) ($this->firstValue($props, 'PERCENT-COMPLETE') ?? 0),
        );
    }

    /**
     * @param array<string, list<array{params: array<string, string>, value: string}>> $props
     */
    private function buildAlarm(array $props): ?VAlarm
    {
        $action  = $this->firstValue($props, 'ACTION');
        $trigger = $this->firstValue($props, 'TRIGGER');
        if ($action === null || $trigger === null) {
            return null;
        }
        return new VAlarm(
            action:      strtoupper(trim($action)),
            trigger:     trim($trigger),
            description: $this->textValue($props, 'DESCRIPTION'),
            summary:     $this->textValue($props, 'SUMMARY'),
        );
    }

    // -------------------------------------------------------------------------
    // Property helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, list<array{params: array<string, string>, value: string}>> $props
     */
    private function firstValue(array $props, string $name): ?string
    {
        return isset($props[$name]) ? $props[$name][0]['value'] : null;
    }

    /**
     * @param array<string, list<array{params: array<string, string>, value: string}>> $props
     */
    private function textValue(array $props, string $name): ?string
    {
        $value = $this->firstValue($props, $name);
        if ($value === null) {
            return null;
        }
        return in_array($name, self::TEXT_PROPERTIES, true) ? self::unescapeText($value) : $value;
    }

    /**
     * @param array<string, list<array{params: array<string, string>, value: string}>> $props
     * @param array<string, DateTimeZone>                                             $tzMap
     */
    private function dateProp(array $props, string $name, array $tzMap): ?DateTimeImmutable
    {
        $entry = $props[$name][0] ?? null;
        return $entry !== null ? $this->parseDateTime($entry['value'], $entry['params'], $tzMap) : null;
    }

    private static function stripMailto(string $value): string
    {
        $value = trim($value);
        return strncasecmp($value, 'mailto:', 7) === 0 ? substr($value, 7) : $value;
    }
}
