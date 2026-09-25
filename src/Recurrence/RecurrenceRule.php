<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Recurrence;

use DateTimeImmutable;
use DateTimeZone;
use Tito10047\Calendar\Enum\DayName;

/**
 * Immutable value object representing an RFC 5545 RRULE.
 *
 * Supported rule parts: FREQ (SECONDLY … YEARLY), INTERVAL, COUNT, UNTIL, BYSECOND, BYMINUTE,
 * BYHOUR, BYDAY (incl. ordinals such as 1MO / -1FR / +2TU), BYMONTHDAY, BYYEARDAY, BYWEEKNO,
 * BYMONTH, BYSETPOS and WKST. Additionally supports RDATE (extra explicit dates merged into the
 * expansion) and EXDATE (exclusions).
 *
 * Expansion is anchored at DTSTART: COUNT, INTERVAL and the implicit defaults (weekday, day of
 * month, month) are always derived from the series start, never from the queried window.
 */
final class RecurrenceRule
{
    /**
     * Hard upper bound on the number of periods examined in one expand() call. Protects against
     * hostile feeds (e.g. FREQ=SECONDLY queried over decades); an expansion that hits the limit
     * is truncated. Ample for normal use: 500 000 days ≈ 1 369 years, or ~1 year of minutes.
     */
    public const MAX_ITERATIONS = 500_000;

    private const DAY_CODES = [
        'MO' => DayName::Monday,
        'TU' => DayName::Tuesday,
        'WE' => DayName::Wednesday,
        'TH' => DayName::Thursday,
        'FR' => DayName::Friday,
        'SA' => DayName::Saturday,
        'SU' => DayName::Sunday,
    ];

    private readonly Frequency $frequency;
    private readonly int $interval;
    private readonly ?int $count;
    private readonly ?DateTimeImmutable $until;
    /** True when UNTIL is a DATE value (inclusive whole day), false when it is an exact instant. */
    private readonly bool $untilIsDate;

    /** @var list<array{int, DayName}> BYDAY rules as [ordinal, day]; ordinal 0 = every such weekday */
    private readonly array $byDayRules;

    /** @var list<int>|null */
    private readonly ?array $byMonth;
    /** @var list<int>|null */
    private readonly ?array $byMonthDay;
    /** @var list<int>|null */
    private readonly ?array $byYearDay;
    /** @var list<int>|null */
    private readonly ?array $byWeekNo;
    /** @var list<int>|null */
    private readonly ?array $byHour;
    /** @var list<int>|null */
    private readonly ?array $byMinute;
    /** @var list<int>|null */
    private readonly ?array $bySecond;
    /** @var list<int>|null */
    private readonly ?array $bySetPos;

    /** Week start day for recurrence calculations (WKST rule part; default Monday). */
    private readonly DayName $wkst;

    /** @var list<DateTimeImmutable> EXDATE values */
    private readonly array $exDates;

    /** @var list<DateTimeImmutable> RDATE values */
    private readonly array $rDates;

    /**
     * @param list<array{int, DayName}> $byDayRules
     * @param list<int>|null            $byMonth
     * @param list<int>|null            $byMonthDay
     * @param list<int>|null            $byYearDay
     * @param list<int>|null            $byWeekNo
     * @param list<int>|null            $byHour
     * @param list<int>|null            $byMinute
     * @param list<int>|null            $bySecond
     * @param list<int>|null            $bySetPos
     * @param list<DateTimeImmutable>   $exDates
     * @param list<DateTimeImmutable>   $rDates
     */
    private function __construct(
        Frequency $frequency,
        int $interval = 1,
        ?int $count = null,
        ?DateTimeImmutable $until = null,
        bool $untilIsDate = false,
        array $byDayRules = [],
        ?array $byMonth = null,
        ?array $byMonthDay = null,
        ?array $byYearDay = null,
        ?array $byWeekNo = null,
        ?array $byHour = null,
        ?array $byMinute = null,
        ?array $bySecond = null,
        ?array $bySetPos = null,
        DayName $wkst = DayName::Monday,
        array $exDates = [],
        array $rDates = [],
    ) {
        if ($interval < 1) {
            throw new \InvalidArgumentException("INTERVAL must be >= 1, got {$interval}");
        }
        if ($count !== null && $count < 1) {
            throw new \InvalidArgumentException("COUNT must be >= 1, got {$count}");
        }
        foreach ($byDayRules as [$nth]) {
            if ($nth < -53 || $nth > 53) {
                throw new \InvalidArgumentException("BYDAY ordinal must be in range -53..53, got {$nth}");
            }
        }
        self::assertRange('BYMONTH', $byMonth, 1, 12, false);
        self::assertRange('BYMONTHDAY', $byMonthDay, 1, 31, true);
        self::assertRange('BYYEARDAY', $byYearDay, 1, 366, true);
        self::assertRange('BYWEEKNO', $byWeekNo, 1, 53, true);
        self::assertRange('BYHOUR', $byHour, 0, 23, false);
        self::assertRange('BYMINUTE', $byMinute, 0, 59, false);
        self::assertRange('BYSECOND', $bySecond, 0, 60, false);
        self::assertRange('BYSETPOS', $bySetPos, 1, 366, true);

        if ($byMonthDay !== null && $frequency === Frequency::Weekly) {
            throw new \InvalidArgumentException('BYMONTHDAY MUST NOT be used with FREQ=WEEKLY (RFC 5545 §3.3.10)');
        }
        if ($byYearDay !== null && in_array($frequency, [Frequency::Daily, Frequency::Weekly, Frequency::Monthly], true)) {
            throw new \InvalidArgumentException("BYYEARDAY MUST NOT be used with FREQ={$frequency->value} (RFC 5545 §3.3.10)");
        }
        if ($byWeekNo !== null && $frequency !== Frequency::Yearly) {
            throw new \InvalidArgumentException('BYWEEKNO MUST only be used with FREQ=YEARLY (RFC 5545 §3.3.10)');
        }

        $this->frequency   = $frequency;
        $this->interval    = $interval;
        $this->count       = $count;
        $this->until       = $until;
        $this->untilIsDate = $until !== null && $untilIsDate;
        $this->byDayRules  = $byDayRules;
        $this->byMonth     = $byMonth;
        $this->byMonthDay  = $byMonthDay;
        $this->byYearDay   = $byYearDay;
        $this->byWeekNo    = $byWeekNo;
        $this->byHour      = $byHour;
        $this->byMinute    = $byMinute;
        $this->bySecond    = $bySecond;
        $this->bySetPos    = $bySetPos;
        $this->wkst        = $wkst;
        $this->exDates     = $exDates;
        $this->rDates      = $rDates;
    }

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    public static function secondly(): self
    {
        return new self(Frequency::Secondly);
    }

    public static function minutely(): self
    {
        return new self(Frequency::Minutely);
    }

    public static function hourly(): self
    {
        return new self(Frequency::Hourly);
    }

    public static function daily(): self
    {
        return new self(Frequency::Daily);
    }

    public static function weekly(): self
    {
        return new self(Frequency::Weekly);
    }

    public static function monthly(): self
    {
        return new self(Frequency::Monthly);
    }

    public static function yearly(): self
    {
        return new self(Frequency::Yearly);
    }

    /**
     * Parse an RRULE value (with or without the "RRULE:" prefix).
     *
     * @throws \InvalidArgumentException when the rule is malformed or violates RFC 5545 constraints
     */
    public static function fromRrule(string $rrule): self
    {
        $rrule = trim($rrule);
        if (strncasecmp($rrule, 'RRULE:', 6) === 0) {
            $rrule = substr($rrule, 6);
        }

        $parts = [];
        foreach (explode(';', $rrule) as $part) {
            if (trim($part) === '') {
                continue;
            }
            [$key, $value] = explode('=', $part, 2) + ['', ''];
            $parts[strtoupper(trim($key))] = trim($value);
        }

        $freqRaw   = $parts['FREQ'] ?? throw new \InvalidArgumentException('RRULE missing FREQ');
        $frequency = Frequency::tryFrom(strtoupper($freqRaw))
            ?? throw new \InvalidArgumentException("Unknown RRULE FREQ: {$freqRaw}");

        $interval = isset($parts['INTERVAL']) ? self::parseInt('INTERVAL', $parts['INTERVAL']) : 1;
        $count    = isset($parts['COUNT']) ? self::parseInt('COUNT', $parts['COUNT']) : null;

        $until       = null;
        $untilIsDate = false;
        if (isset($parts['UNTIL'])) {
            [$until, $untilIsDate] = self::parseUntil($parts['UNTIL']);
        }

        $byDayRules = isset($parts['BYDAY']) ? self::parseByday($parts['BYDAY']) : [];

        $wkst = DayName::Monday;
        if (isset($parts['WKST'])) {
            $wkst = self::DAY_CODES[strtoupper($parts['WKST'])]
                ?? throw new \InvalidArgumentException("Unknown WKST day code: {$parts['WKST']}");
        }

        return new self(
            frequency:   $frequency,
            interval:    $interval,
            count:       $count,
            until:       $until,
            untilIsDate: $untilIsDate,
            byDayRules:  $byDayRules,
            byMonth:     self::parseIntList('BYMONTH', $parts['BYMONTH'] ?? null),
            byMonthDay:  self::parseIntList('BYMONTHDAY', $parts['BYMONTHDAY'] ?? null),
            byYearDay:   self::parseIntList('BYYEARDAY', $parts['BYYEARDAY'] ?? null),
            byWeekNo:    self::parseIntList('BYWEEKNO', $parts['BYWEEKNO'] ?? null),
            byHour:      self::parseIntList('BYHOUR', $parts['BYHOUR'] ?? null),
            byMinute:    self::parseIntList('BYMINUTE', $parts['BYMINUTE'] ?? null),
            bySecond:    self::parseIntList('BYSECOND', $parts['BYSECOND'] ?? null),
            bySetPos:    self::parseIntList('BYSETPOS', $parts['BYSETPOS'] ?? null),
            wkst:        $wkst,
        );
    }

    // -------------------------------------------------------------------------
    // Immutable withers
    // -------------------------------------------------------------------------

    /** Set BYDAY to the given weekdays (replaces any previous BYDAY rules). */
    public function onDays(DayName ...$days): self
    {
        return $this->with(['byDayRules' => array_map(static fn (DayName $d) => [0, $d], array_values($days))]);
    }

    /**
     * Set BYDAY to a single ordinal weekday, e.g. onNthWeekday(-1, DayName::Friday) = last Friday
     * (replaces any previous BYDAY rules). Use withNthWeekday() to add more.
     */
    public function onNthWeekday(int $nth, DayName $day): self
    {
        self::assertOrdinal($nth);
        return $this->with(['byDayRules' => [[$nth, $day]]]);
    }

    /** Add an ordinal weekday to the existing BYDAY rules, e.g. …->withNthWeekday(1, DayName::Friday). */
    public function withNthWeekday(int $nth, DayName $day): self
    {
        self::assertOrdinal($nth);
        $rules   = $this->byDayRules;
        $rules[] = [$nth, $day];
        return $this->with(['byDayRules' => $rules]);
    }

    public function every(int $interval): self
    {
        return $this->with(['interval' => $interval]);
    }

    public function limitTo(int $occurrences): self
    {
        if ($occurrences < 1) {
            throw new \InvalidArgumentException("COUNT must be >= 1, got {$occurrences}");
        }
        return $this->with(['count' => $occurrences, 'until' => null, 'untilIsDate' => false]);
    }

    /**
     * Set UNTIL. A value at exactly midnight is treated as a DATE (the whole day is inclusive);
     * any other value is an exact inclusive instant.
     */
    public function until(DateTimeImmutable $until): self
    {
        $isDate = $until->format('H:i:s') === '00:00:00';
        return $this->with(['count' => null, 'until' => $until, 'untilIsDate' => $isDate]);
    }

    /**
     * Exclude occurrences (EXDATE). A value at exactly midnight excludes the whole calendar day;
     * any other value excludes only the occurrence starting at that exact instant.
     */
    public function excluding(DateTimeImmutable ...$dates): self
    {
        return $this->with(['exDates' => self::mergeDates($this->exDates, $dates)]);
    }

    /** Set BYMONTH constraint (month numbers 1–12). */
    public function onMonths(int ...$months): self
    {
        return $this->with(['byMonth' => array_values($months)]);
    }

    /** Set BYMONTHDAY constraint (1–31 or -31–-1; negative counts from end of month). */
    public function onMonthDays(int ...$days): self
    {
        return $this->with(['byMonthDay' => array_values($days)]);
    }

    /** Set BYYEARDAY constraint (1–366 or -366–-1). Only valid with YEARLY and sub-daily frequencies. */
    public function onYearDays(int ...$days): self
    {
        return $this->with(['byYearDay' => array_values($days)]);
    }

    /** Set BYWEEKNO constraint (1–53 or -53–-1). Only valid with FREQ=YEARLY. */
    public function onWeekNumbers(int ...$weeks): self
    {
        return $this->with(['byWeekNo' => array_values($weeks)]);
    }

    /** Set BYHOUR (0–23). */
    public function atHours(int ...$hours): self
    {
        return $this->with(['byHour' => array_values($hours)]);
    }

    /** Set BYMINUTE (0–59). */
    public function atMinutes(int ...$minutes): self
    {
        return $this->with(['byMinute' => array_values($minutes)]);
    }

    /** Set BYSECOND (0–60). */
    public function atSeconds(int ...$seconds): self
    {
        return $this->with(['bySecond' => array_values($seconds)]);
    }

    /**
     * Set BYSETPOS constraint — selects the N-th occurrence(s) from the per-period candidate set.
     * Positive positions count from the start (1 = first), negative from the end (-1 = last).
     */
    public function bySetPos(int ...$positions): self
    {
        return $this->with(['bySetPos' => array_values($positions)]);
    }

    /**
     * Add explicit extra dates (RDATE) that are always included in the expansion,
     * regardless of the RRULE pattern. A value at exactly midnight is treated as a DATE and
     * receives the series' start time; any other value is used as-is.
     */
    public function withExtraDates(DateTimeImmutable ...$dates): self
    {
        return $this->with(['rDates' => self::mergeDates($this->rDates, $dates)]);
    }

    /** Set WKST — the first day of the week for recurrence calculations (default: Monday). */
    public function weekStart(DayName $day): self
    {
        return $this->with(['wkst' => $day]);
    }

    // -------------------------------------------------------------------------
    // Expansion
    // -------------------------------------------------------------------------

    /**
     * Expand the rule to occurrence start times within the days [from, to] (both inclusive,
     * day boundaries taken in $from's / $to's timezone).
     *
     * $dtStart anchors the series (COUNT, INTERVAL and implicit defaults are counted from it) and
     * provides the time of day and timezone of each occurrence. When omitted, the series is
     * anchored at midnight of $from — convenient for rule-only usage, but pass the real DTSTART
     * whenever the rule belongs to an event.
     *
     * @return list<DateTimeImmutable> sorted ascending
     */
    public function expand(DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeImmutable $dtStart = null): array
    {
        $rangeStart = $from->setTime(0, 0, 0);
        $rangeEnd   = $to->setTime(0, 0, 0)->modify('+1 day'); // exclusive
        if ($rangeEnd <= $rangeStart) {
            return [];
        }
        $dtStart ??= $rangeStart;

        [$exInstants, $exDays] = $this->exclusionIndex();
        $isExcluded = static function (DateTimeImmutable $occ) use ($exInstants, $exDays): bool {
            return isset($exInstants[$occ->getTimestamp()]) || isset($exDays[$occ->format('Y-m-d')]);
        };

        $results = [];
        foreach ($this->generate($dtStart, $rangeStart, $rangeEnd) as $occurrence) {
            if (!$isExcluded($occurrence)) {
                $results[$occurrence->getTimestamp()] = $occurrence;
            }
        }

        // RDATE — part of the recurrence set, not subject to COUNT / UNTIL
        foreach ($this->rDates as $rDate) {
            $occurrence = $rDate->format('H:i:s') === '00:00:00'
                ? $dtStart->setDate((int) $rDate->format('Y'), (int) $rDate->format('n'), (int) $rDate->format('j'))
                : $rDate;
            if ($occurrence >= $rangeStart && $occurrence < $rangeEnd && !$isExcluded($occurrence)) {
                $results[$occurrence->getTimestamp()] ??= $occurrence;
            }
        }

        ksort($results);

        return array_values($results);
    }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    /**
     * Serialise to an RRULE value (without the "RRULE:" prefix).
     *
     * Pass the series' DTSTART (and whether it is a DATE value) so UNTIL is emitted in the value
     * type RFC 5545 requires: a DATE for all-day series, a UTC DATE-TIME otherwise.
     */
    public function toRruleString(?DateTimeImmutable $dtStart = null, bool $dtStartIsDate = false): string
    {
        $parts = ['FREQ=' . $this->frequency->value];

        if ($this->interval !== 1) {
            $parts[] = 'INTERVAL=' . $this->interval;
        }
        if ($this->count !== null) {
            $parts[] = 'COUNT=' . $this->count;
        }
        if ($this->until !== null) {
            $parts[] = 'UNTIL=' . $this->formatUntil($dtStart, $dtStartIsDate);
        }
        if ($this->bySecond !== null) {
            $parts[] = 'BYSECOND=' . implode(',', $this->bySecond);
        }
        if ($this->byMinute !== null) {
            $parts[] = 'BYMINUTE=' . implode(',', $this->byMinute);
        }
        if ($this->byHour !== null) {
            $parts[] = 'BYHOUR=' . implode(',', $this->byHour);
        }
        if ($this->byDayRules !== []) {
            $parts[] = 'BYDAY=' . implode(',', array_map(
                static fn (array $rule) => ($rule[0] !== 0 ? (string) $rule[0] : '') . self::dayToRruleCode($rule[1]),
                $this->byDayRules,
            ));
        }
        if ($this->byMonth !== null) {
            $parts[] = 'BYMONTH=' . implode(',', $this->byMonth);
        }
        if ($this->byMonthDay !== null) {
            $parts[] = 'BYMONTHDAY=' . implode(',', $this->byMonthDay);
        }
        if ($this->byYearDay !== null) {
            $parts[] = 'BYYEARDAY=' . implode(',', $this->byYearDay);
        }
        if ($this->byWeekNo !== null) {
            $parts[] = 'BYWEEKNO=' . implode(',', $this->byWeekNo);
        }
        if ($this->bySetPos !== null) {
            $parts[] = 'BYSETPOS=' . implode(',', $this->bySetPos);
        }
        if ($this->wkst !== DayName::Monday) {
            $parts[] = 'WKST=' . self::dayToRruleCode($this->wkst);
        }

        return implode(';', $parts);
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getFrequency(): Frequency
    {
        return $this->frequency;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function getUntil(): ?DateTimeImmutable
    {
        return $this->until;
    }

    /** True when UNTIL is a DATE (whole day inclusive) rather than an exact instant. */
    public function isUntilDate(): bool
    {
        return $this->untilIsDate;
    }

    /** @return list<DayName> Plain (non-ordinal) BYDAY weekdays. */
    public function getByDay(): array
    {
        $days = [];
        foreach ($this->byDayRules as [$nth, $day]) {
            if ($nth === 0) {
                $days[] = $day;
            }
        }
        return $days;
    }

    /**
     * Ordinal BYDAY entries keyed by ordinal. Lossy when two entries share an ordinal
     * (e.g. 1MO,1FR) — use getByDayRules() for the complete list.
     *
     * @return array<int, DayName>|null
     */
    public function getByNthWeekday(): ?array
    {
        $nth = null;
        foreach ($this->byDayRules as [$n, $day]) {
            if ($n !== 0) {
                $nth ??= [];
                $nth[$n] = $day;
            }
        }
        return $nth;
    }

    /** @return list<array{int, DayName}> All BYDAY rules as [ordinal, day]; ordinal 0 = every such weekday. */
    public function getByDayRules(): array
    {
        return $this->byDayRules;
    }

    /** @return list<int>|null */
    public function getByMonth(): ?array
    {
        return $this->byMonth;
    }

    /** @return list<int>|null */
    public function getByMonthDay(): ?array
    {
        return $this->byMonthDay;
    }

    /** @return list<int>|null */
    public function getByYearDay(): ?array
    {
        return $this->byYearDay;
    }

    /** @return list<int>|null */
    public function getByWeekNo(): ?array
    {
        return $this->byWeekNo;
    }

    /** @return list<int>|null */
    public function getByHour(): ?array
    {
        return $this->byHour;
    }

    /** @return list<int>|null */
    public function getByMinute(): ?array
    {
        return $this->byMinute;
    }

    /** @return list<int>|null */
    public function getBySecond(): ?array
    {
        return $this->bySecond;
    }

    /** @return list<int>|null */
    public function getBySetPos(): ?array
    {
        return $this->bySetPos;
    }

    /** @return list<string> Extra explicit occurrence dates (RDATE) in Y-m-d format. */
    public function getRDates(): array
    {
        return array_values(array_unique(array_map(static fn (DateTimeImmutable $d) => $d->format('Y-m-d'), $this->rDates)));
    }

    /** @return list<DateTimeImmutable> Extra explicit occurrences (RDATE). */
    public function getExtraDates(): array
    {
        return $this->rDates;
    }

    /** @return list<DateTimeImmutable> Excluded occurrences (EXDATE). */
    public function getExDates(): array
    {
        return $this->exDates;
    }

    /** Week start day (WKST); default Monday. */
    public function getWkst(): DayName
    {
        return $this->wkst;
    }

    // -------------------------------------------------------------------------
    // Expansion engine
    // -------------------------------------------------------------------------

    /**
     * Generate RRULE occurrences (COUNT / UNTIL applied) that fall in [rangeStart, rangeEnd).
     *
     * @return list<DateTimeImmutable>
     */
    private function generate(DateTimeImmutable $dtStart, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        if ($rangeEnd <= $dtStart) {
            return [];
        }
        return $this->frequency->isSubDaily()
            ? $this->generateSubDaily($dtStart, $rangeStart, $rangeEnd)
            : $this->generateDaily($dtStart, $rangeStart, $rangeEnd);
    }

    /**
     * DAILY, WEEKLY, MONTHLY and YEARLY expansion.
     *
     * @return list<DateTimeImmutable>
     */
    private function generateDaily(DateTimeImmutable $dtStart, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        $startDay = self::civilToDays((int) $dtStart->format('Y'), (int) $dtStart->format('n'), (int) $dtStart->format('j'));
        $filters  = $this->effectiveFilters($dtStart);
        $times    = $this->timeSet($dtStart);
        $untilDay = $this->untilDay();

        $period = 0;
        if ($this->count === null) {
            $period = $this->periodsToSkip($startDay, $rangeStart->setTimezone($dtStart->getTimezone()));
        }

        $out     = [];
        $emitted = 0;
        for ($guard = 0; $guard < self::MAX_ITERATIONS; $guard++, $period++) {
            [$days, $firstDay, $periodYear] = $this->periodDays($startDay, $period);

            $periodStart = self::dateAt($dtStart, $firstDay, 0, 0, 0);
            if ($periodStart >= $rangeEnd) {
                break;
            }
            if ($this->until !== null && ($untilDay !== null ? $firstDay > $untilDay : $periodStart > $this->until)) {
                break;
            }

            $candidates = [];
            foreach ($days as $day) {
                if (!$this->dayMatches($day, $filters, $periodYear)) {
                    continue;
                }
                foreach ($times as [$h, $i, $s]) {
                    $candidate                              = self::dateAt($dtStart, $day, $h, $i, $s);
                    $candidates[$candidate->getTimestamp()] = $candidate;
                }
            }
            ksort($candidates);

            foreach ($this->applyBySetPos(array_values($candidates)) as $candidate) {
                if ($candidate < $dtStart) {
                    continue;
                }
                if (!$this->withinUntil($candidate, $untilDay) || $candidate >= $rangeEnd) {
                    break 2;
                }
                $emitted++;
                if ($candidate >= $rangeStart) {
                    $out[] = $candidate;
                }
                if ($this->count !== null && $emitted >= $this->count) {
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * HOURLY, MINUTELY and SECONDLY expansion. Periods are exact elapsed-time steps from DTSTART.
     *
     * @return list<DateTimeImmutable>
     */
    private function generateSubDaily(DateTimeImmutable $dtStart, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
    {
        $unit     = $this->frequency->seconds();
        $step     = $unit * $this->interval;
        $t0       = $dtStart->getTimestamp();
        $filters  = $this->effectiveFilters($dtStart);
        $untilDay = $this->untilDay();

        $period = 0;
        if ($this->count === null && $rangeStart->getTimestamp() > $t0) {
            $period = max(0, intdiv($rangeStart->getTimestamp() - $t0, $step) - 1);
        }

        $out     = [];
        $emitted = 0;
        for ($guard = 0; $guard < self::MAX_ITERATIONS; $guard++) {
            $local = $dtStart->setTimestamp($t0 + $period * $step);

            // Truncate to the start of the period (hour / minute / second)
            $h           = (int) $local->format('G');
            $i           = $this->frequency === Frequency::Hourly ? 0 : (int) $local->format('i');
            $s           = $this->frequency === Frequency::Secondly ? (int) $local->format('s') : 0;
            $periodStart = $local->setTime($h, $i, $s);
            if ($periodStart >= $rangeEnd) {
                break;
            }
            if ($this->until !== null && ($untilDay !== null
                    ? $periodStart->format('Ymd') > $this->until->format('Ymd')
                    : $periodStart > $this->until)) {
                break;
            }

            $day = self::civilToDays((int) $local->format('Y'), (int) $local->format('n'), (int) $local->format('j'));
            if (!$this->dayMatches($day, $filters, (int) $local->format('Y'))) {
                $nextDay = $local->setTime(0, 0, 0)->modify('+1 day')->getTimestamp();
                $period  = max($period + 1, intdiv($nextDay - $t0 + $step - 1, $step));
                continue;
            }
            if ($this->byHour !== null && !in_array($h, $this->byHour, true)) {
                $nextHour = $local->setTime($h, 0, 0)->getTimestamp() + 3600;
                $period   = max($period + 1, intdiv($nextHour - $t0 + $step - 1, $step));
                continue;
            }
            if ($this->frequency !== Frequency::Hourly && $this->byMinute !== null && !in_array($i, $this->byMinute, true)) {
                $period++;
                continue;
            }
            if ($this->frequency === Frequency::Secondly && $this->bySecond !== null && !in_array($s, $this->bySecond, true)) {
                $period++;
                continue;
            }

            $minutes = $this->frequency === Frequency::Hourly ? ($this->byMinute ?? [(int) $dtStart->format('i')]) : [$i];
            $seconds = $this->frequency === Frequency::Secondly ? [$s] : ($this->bySecond ?? [(int) $dtStart->format('s')]);

            $candidates = [];
            foreach ($minutes as $minute) {
                foreach ($seconds as $second) {
                    $candidate                              = $local->setTime($h, $minute, $second);
                    $candidates[$candidate->getTimestamp()] = $candidate;
                }
            }
            ksort($candidates);

            foreach ($this->applyBySetPos(array_values($candidates)) as $candidate) {
                if ($candidate < $dtStart) {
                    continue;
                }
                if (!$this->withinUntil($candidate, $untilDay) || $candidate >= $rangeEnd) {
                    break 2;
                }
                $emitted++;
                if ($candidate >= $rangeStart) {
                    $out[] = $candidate;
                }
                if ($this->count !== null && $emitted >= $this->count) {
                    break 2;
                }
            }

            $period++;
        }

        return $out;
    }

    /**
     * Day-level filters with RFC 5545 defaults derived from DTSTART applied.
     *
     * @return array{month: list<int>|null, monthDay: list<int>|null, dayRules: list<array{int, DayName}>}
     */
    private function effectiveFilters(DateTimeImmutable $dtStart): array
    {
        $month    = $this->byMonth;
        $monthDay = $this->byMonthDay;
        $dayRules = $this->byDayRules;

        $hasDayLevel = $this->byWeekNo !== null || $this->byYearDay !== null
            || $this->byMonthDay !== null || $this->byDayRules !== [];

        if (!$hasDayLevel) {
            switch ($this->frequency) {
                case Frequency::Yearly:
                    $month ??= [(int) $dtStart->format('n')];
                    $monthDay = [(int) $dtStart->format('j')];
                    break;
                case Frequency::Monthly:
                    $monthDay = [(int) $dtStart->format('j')];
                    break;
                case Frequency::Weekly:
                    $dayRules = [[0, DayName::fromDate($dtStart)]];
                    break;
                default:
                    break;
            }
        }

        return ['month' => $month, 'monthDay' => $monthDay, 'dayRules' => $dayRules];
    }

    /**
     * Days (as day numbers) of the $period-th period of the series.
     *
     * @return array{list<int>, int, int} [days, first day of the period, year the period belongs to]
     */
    private function periodDays(int $startDay, int $period): array
    {
        $steps = $period * $this->interval;

        switch ($this->frequency) {
            case Frequency::Weekly:
                $first = $this->weekStartOf($startDay) + $steps * 7;
                return [range($first, $first + 6), $first, self::daysToCivil($first)[0]];

            case Frequency::Monthly:
                [$y, $m] = self::daysToCivil($startDay);
                $index   = $y * 12 + ($m - 1) + $steps;
                $y       = intdiv($index, 12);
                $m       = $index % 12 + 1;
                $first   = self::civilToDays($y, $m, 1);
                return [range($first, $first + self::daysInMonth($y, $m) - 1), $first, $y];

            case Frequency::Yearly:
                $y     = self::daysToCivil($startDay)[0] + $steps;
                $first = self::civilToDays($y, 1, 1);
                $last  = self::civilToDays($y, 12, 31);
                if ($this->byWeekNo !== null) {
                    // Week 1 may start in the previous year and the last week may end in the next one
                    $first -= 6;
                    $last  += 6;
                }
                return [range($first, $last), $first, $y];

            default: // Daily
                $day = $startDay + $steps;
                return [[$day], $day, self::daysToCivil($day)[0]];
        }
    }

    /**
     * @param array{month: list<int>|null, monthDay: list<int>|null, dayRules: list<array{int, DayName}>} $filters
     */
    private function dayMatches(int $day, array $filters, int $periodYear): bool
    {
        [$y, $m, $d] = self::daysToCivil($day);

        if ($filters['month'] !== null && !in_array($m, $filters['month'], true)) {
            return false;
        }

        if ($this->byWeekNo !== null) {
            [$weekYear, $weekNo, $weeksInYear] = $this->weekNumber($day);
            if ($weekYear !== $periodYear) {
                return false;
            }
            $match = false;
            foreach ($this->byWeekNo as $wn) {
                if ($wn === $weekNo || $weeksInYear + $wn + 1 === $weekNo) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return false;
            }
        } elseif ($y !== $periodYear && $this->frequency === Frequency::Yearly) {
            return false;
        }

        if ($this->byYearDay !== null) {
            $dayOfYear  = $day - self::civilToDays($y, 1, 1) + 1;
            $daysInYear = self::civilToDays($y + 1, 1, 1) - self::civilToDays($y, 1, 1);
            $match      = false;
            foreach ($this->byYearDay as $yd) {
                if ($yd === $dayOfYear || $daysInYear + $yd + 1 === $dayOfYear) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return false;
            }
        }

        if ($filters['monthDay'] !== null) {
            $daysInMonth = self::daysInMonth($y, $m);
            $match       = false;
            foreach ($filters['monthDay'] as $md) {
                if ($md === $d || $daysInMonth + $md + 1 === $d) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return false;
            }
        }

        if ($filters['dayRules'] !== []) {
            $weekday = self::weekday($day);
            $match   = false;
            foreach ($filters['dayRules'] as [$nth, $dayName]) {
                if ($dayName->value !== $weekday) {
                    continue;
                }
                if ($nth === 0 || $this->ordinalMatches($day, $y, $m, $nth)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return false;
            }
        }

        return true;
    }

    /** Whether $day is the $nth (±) weekday of its month (MONTHLY / YEARLY+BYMONTH) or year (YEARLY). */
    private function ordinalMatches(int $day, int $y, int $m, int $nth): bool
    {
        if ($this->frequency === Frequency::Monthly || ($this->frequency === Frequency::Yearly && $this->byMonth !== null)) {
            $first = self::civilToDays($y, $m, 1);
            $last  = $first + self::daysInMonth($y, $m) - 1;
        } elseif ($this->frequency === Frequency::Yearly && $this->byWeekNo === null) {
            $first = self::civilToDays($y, 1, 1);
            $last  = self::civilToDays($y, 12, 31);
        } else {
            return true; // ordinals are meaningless for other frequencies — treat as a plain weekday
        }

        return $nth > 0
            ? intdiv($day - $first, 7) + 1 === $nth
            : -(intdiv($last - $day, 7) + 1) === $nth;
    }

    /** @return list<array{int, int, int}> sorted [hour, minute, second] triples for DAILY and coarser */
    private function timeSet(DateTimeImmutable $dtStart): array
    {
        $hours   = $this->byHour ?? [(int) $dtStart->format('G')];
        $minutes = $this->byMinute ?? [(int) $dtStart->format('i')];
        $seconds = $this->bySecond ?? [(int) $dtStart->format('s')];
        sort($hours);
        sort($minutes);
        sort($seconds);

        $times = [];
        foreach ($hours as $h) {
            foreach ($minutes as $i) {
                foreach ($seconds as $s) {
                    $times[] = [$h, $i, $s];
                }
            }
        }
        return $times;
    }

    /** Number of whole periods that can be skipped without affecting results (only valid without COUNT). */
    private function periodsToSkip(int $startDay, DateTimeImmutable $rangeStartLocal): int
    {
        $rangeDay = self::civilToDays((int) $rangeStartLocal->format('Y'), (int) $rangeStartLocal->format('n'), (int) $rangeStartLocal->format('j'));
        if ($rangeDay <= $startDay) {
            return 0;
        }

        [$sy, $sm] = self::daysToCivil($startDay);
        [$ry, $rm] = self::daysToCivil($rangeDay);

        $diff = match ($this->frequency) {
            Frequency::Weekly  => intdiv($this->weekStartOf($rangeDay) - $this->weekStartOf($startDay), 7),
            Frequency::Monthly => ($ry * 12 + $rm) - ($sy * 12 + $sm),
            Frequency::Yearly  => $ry - $sy,
            default            => $rangeDay - $startDay,
        };

        return max(0, intdiv($diff, $this->interval) - 1);
    }

    private function untilDay(): ?int
    {
        if ($this->until === null || !$this->untilIsDate) {
            return null;
        }
        return self::civilToDays((int) $this->until->format('Y'), (int) $this->until->format('n'), (int) $this->until->format('j'));
    }

    private function withinUntil(DateTimeImmutable $candidate, ?int $untilDay): bool
    {
        if ($this->until === null) {
            return true;
        }
        if ($untilDay !== null) {
            return $candidate->format('Ymd') <= $this->until->format('Ymd');
        }
        return $candidate <= $this->until;
    }

    /**
     * Apply BYSETPOS filter to a sorted list of period candidates.
     *
     * @param  list<DateTimeImmutable> $candidates
     * @return list<DateTimeImmutable>
     */
    private function applyBySetPos(array $candidates): array
    {
        if ($this->bySetPos === null || $candidates === []) {
            return $candidates;
        }
        $total    = count($candidates);
        $selected = [];
        foreach ($this->bySetPos as $pos) {
            $idx = $pos > 0 ? $pos - 1 : $total + $pos;
            if ($idx >= 0 && $idx < $total) {
                $selected[$idx] = $candidates[$idx];
            }
        }
        ksort($selected);
        return array_values($selected);
    }

    /** @return array{array<int, true>, array<string, true>} [exact instants, whole-day exclusions] */
    private function exclusionIndex(): array
    {
        $instants = [];
        $days     = [];
        foreach ($this->exDates as $ex) {
            $instants[$ex->getTimestamp()] = true;
            if ($ex->format('H:i:s') === '00:00:00') {
                $days[$ex->format('Y-m-d')] = true;
            }
        }
        return [$instants, $days];
    }

    /** First day (per WKST) of the week containing $day. */
    private function weekStartOf(int $day): int
    {
        return $day - ((self::weekday($day) - $this->wkst->value + 7) % 7);
    }

    /** @return array{int, int, int} [week-numbering year, week number, number of weeks in that year] */
    private function weekNumber(int $day): array
    {
        $y  = self::daysToCivil($day)[0];
        $w1 = $this->firstWeekStart($y);
        if ($day < $w1) {
            $y--;
            $w1 = $this->firstWeekStart($y);
        } elseif ($day >= $this->firstWeekStart($y + 1)) {
            $y++;
            $w1 = $this->firstWeekStart($y);
        }
        $weeks = intdiv($this->firstWeekStart($y + 1) - $w1, 7);
        return [$y, intdiv($day - $w1, 7) + 1, $weeks];
    }

    /** Start of week 1 of $year: the first WKST-based week containing at least 4 days of the year. */
    private function firstWeekStart(int $year): int
    {
        $jan1   = self::civilToDays($year, 1, 1);
        $offset = (self::weekday($jan1) - $this->wkst->value + 7) % 7;
        return 7 - $offset >= 4 ? $jan1 - $offset : $jan1 - $offset + 7;
    }

    private function formatUntil(?DateTimeImmutable $dtStart, bool $dtStartIsDate): string
    {
        assert($this->until !== null);
        $utc = new DateTimeZone('UTC');

        if ($dtStartIsDate) {
            return $this->until->format('Ymd');
        }
        if ($this->untilIsDate) {
            if ($dtStart === null) {
                return $this->until->format('Ymd') . 'T235959Z';
            }
            $endOfDay = $dtStart->setDate((int) $this->until->format('Y'), (int) $this->until->format('n'), (int) $this->until->format('j'))
                ->setTime(23, 59, 59);
            return $endOfDay->setTimezone($utc)->format('Ymd\THis\Z');
        }
        return $this->until->setTimezone($utc)->format('Ymd\THis\Z');
    }

    // -------------------------------------------------------------------------
    // Construction helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        $args = array_merge([
            'frequency'   => $this->frequency,
            'interval'    => $this->interval,
            'count'       => $this->count,
            'until'       => $this->until,
            'untilIsDate' => $this->untilIsDate,
            'byDayRules'  => $this->byDayRules,
            'byMonth'     => $this->byMonth,
            'byMonthDay'  => $this->byMonthDay,
            'byYearDay'   => $this->byYearDay,
            'byWeekNo'    => $this->byWeekNo,
            'byHour'      => $this->byHour,
            'byMinute'    => $this->byMinute,
            'bySecond'    => $this->bySecond,
            'bySetPos'    => $this->bySetPos,
            'wkst'        => $this->wkst,
            'exDates'     => $this->exDates,
            'rDates'      => $this->rDates,
        ], $changes);

        return new self(...$args);
    }

    /**
     * @param  list<DateTimeImmutable> $existing
     * @param  array<DateTimeImmutable> $added
     * @return list<DateTimeImmutable>
     */
    private static function mergeDates(array $existing, array $added): array
    {
        $merged = [];
        foreach ([...$existing, ...$added] as $date) {
            $merged[$date->format('Y-m-d\TH:i:s') . '@' . $date->getTimezone()->getName()] = $date;
        }
        return array_values($merged);
    }

    /** @param list<int>|null $values */
    private static function assertRange(string $part, ?array $values, int $min, int $max, bool $allowNegative): void
    {
        if ($values === null) {
            return;
        }
        if ($values === []) {
            throw new \InvalidArgumentException("{$part} must not be empty");
        }
        foreach ($values as $value) {
            $abs = $allowNegative ? abs($value) : $value;
            if ($abs < $min || $abs > $max || ($allowNegative && $value === 0)) {
                $range = $allowNegative ? "{$min}..{$max} or -{$max}..-{$min}" : "{$min}..{$max}";
                throw new \InvalidArgumentException("{$part} value must be in range {$range}, got {$value}");
            }
        }
    }

    private static function assertOrdinal(int $nth): void
    {
        if ($nth === 0 || $nth < -53 || $nth > 53) {
            throw new \InvalidArgumentException("BYDAY ordinal must be non-zero and in range -53..53, got {$nth}");
        }
    }

    private static function parseInt(string $part, string $value): int
    {
        if (!preg_match('/^[+-]?\d+$/', trim($value))) {
            throw new \InvalidArgumentException("{$part} must be an integer, got '{$value}'");
        }
        return (int) $value;
    }

    /** @return list<int>|null */
    private static function parseIntList(string $part, ?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        return array_map(static fn (string $v) => self::parseInt($part, $v), explode(',', $value));
    }

    /** @return array{DateTimeImmutable, bool} [until, isDate] */
    private static function parseUntil(string $raw): array
    {
        $raw = strtoupper(trim($raw));

        if (preg_match('/^\d{8}$/', $raw)) {
            $until = DateTimeImmutable::createFromFormat('!Ymd', $raw);
            if ($until !== false) {
                return [$until, true];
            }
        } elseif (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
            $until = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $raw, new DateTimeZone('UTC'));
            if ($until !== false) {
                return [$until, false];
            }
        } elseif (preg_match('/^\d{8}T\d{6}$/', $raw)) {
            // Floating UNTIL (only legal with a floating DTSTART) — interpreted in the default timezone
            $until = DateTimeImmutable::createFromFormat('Ymd\THis', $raw);
            if ($until !== false) {
                return [$until, false];
            }
        }

        throw new \InvalidArgumentException("Cannot parse UNTIL date: {$raw}");
    }

    /** @return list<array{int, DayName}> */
    private static function parseByday(string $byday): array
    {
        $rules = [];
        foreach (explode(',', $byday) as $token) {
            $token = strtoupper(trim($token));
            if (!preg_match('/^([+-]?\d{1,2})?([A-Z]{2})$/', $token, $m)) {
                throw new \InvalidArgumentException("Invalid BYDAY value: {$token}");
            }
            $day = self::DAY_CODES[$m[2]] ?? throw new \InvalidArgumentException("Unknown BYDAY day code: {$m[2]}");
            $nth = 0;
            if ($m[1] !== '') {
                $nth = (int) $m[1];
                self::assertOrdinal($nth);
            }
            $rules[] = [$nth, $day];
        }
        return $rules;
    }

    private static function dayToRruleCode(DayName $day): string
    {
        return (string) array_search($day, self::DAY_CODES, true);
    }

    // -------------------------------------------------------------------------
    // Calendar arithmetic on day numbers (days since 1970-01-01, proleptic Gregorian)
    // -------------------------------------------------------------------------

    private static function dateAt(DateTimeImmutable $base, int $day, int $h, int $i, int $s): DateTimeImmutable
    {
        [$y, $m, $d] = self::daysToCivil($day);
        return $base->setDate($y, $m, $d)->setTime($h, $i, $s);
    }

    private static function civilToDays(int $y, int $m, int $d): int
    {
        $y  -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m > 2 ? $m - 3 : $m + 9) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** @return array{int, int, int} [year, month, day] */
    private static function daysToCivil(int $z): array
    {
        $z  += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp  = intdiv(5 * $doy + 2, 153);
        $d   = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m   = $mp < 10 ? $mp + 3 : $mp - 9;
        $y   = $yoe + $era * 400 + ($m <= 2 ? 1 : 0);
        return [$y, $m, $d];
    }

    /** ISO weekday (1 = Monday … 7 = Sunday) of a day number. */
    private static function weekday(int $day): int
    {
        return (($day % 7) + 10) % 7 + 1;
    }

    private static function daysInMonth(int $y, int $m): int
    {
        return $m === 12
            ? 31
            : self::civilToDays($y, $m + 1, 1) - self::civilToDays($y, $m, 1);
    }
}
