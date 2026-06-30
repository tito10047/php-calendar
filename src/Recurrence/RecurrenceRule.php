<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Recurrence;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\DayName;

/**
 * Immutable value object representing an RFC 5545 RRULE.
 *
 * Supported rule parts: FREQ, INTERVAL, COUNT, UNTIL, BYDAY, BYMONTH, BYMONTHDAY, BYSETPOS.
 */
final class RecurrenceRule
{
    /** @var list<DayName> */
    private readonly array $byDay;

    /** @var array<int, DayName>|null nth-weekday pairs [n => DayName], null = not set */
    private readonly ?array $byNthWeekday;

    /** @var list<int>|null BYMONTH month numbers 1-12 */
    private readonly ?array $byMonth;

    /** @var list<int>|null BYMONTHDAY day-of-month numbers 1-31 */
    private readonly ?array $byMonthDay;

    /** @var list<string> EXDATE exclusion dates in Y-m-d format */
    private readonly array $exDates;

    /**
     * @param list<DayName>            $byDay
     * @param array<int, DayName>|null $byNthWeekday
     * @param list<int>|null           $byMonth
     * @param list<int>|null           $byMonthDay
     * @param list<string>             $exDates
     */
    private function __construct(
        private readonly Frequency $frequency,
        private readonly int $interval,
        private readonly ?int $count,
        private readonly ?DateTimeImmutable $until,
        array $byDay,
        ?array $byNthWeekday,
        ?array $byMonth,
        ?array $byMonthDay,
        array $exDates,
    ) {
        $this->byDay        = $byDay;
        $this->byNthWeekday = $byNthWeekday;
        $this->byMonth      = $byMonth;
        $this->byMonthDay   = $byMonthDay;
        $this->exDates      = $exDates;
    }

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    public static function daily(): self
    {
        return new self(Frequency::Daily, 1, null, null, [], null, null, null, []);
    }

    public static function weekly(): self
    {
        return new self(Frequency::Weekly, 1, null, null, [], null, null, null, []);
    }

    public static function monthly(): self
    {
        return new self(Frequency::Monthly, 1, null, null, [], null, null, null, []);
    }

    public static function yearly(): self
    {
        return new self(Frequency::Yearly, 1, null, null, [], null, null, null, []);
    }

    public static function fromRrule(string $rrule): self
    {
        $rrule = str_starts_with($rrule, 'RRULE:') ? substr($rrule, 6) : $rrule;
        $parts = [];
        foreach (explode(';', $rrule) as $part) {
            [$key, $value] = explode('=', $part, 2) + ['', ''];
            $parts[strtoupper(trim($key))] = trim($value);
        }

        $frequency = Frequency::from($parts['FREQ'] ?? throw new \InvalidArgumentException('RRULE missing FREQ'));
        $interval  = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;
        $count     = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        $until     = null;
        if (isset($parts['UNTIL'])) {
            $raw     = $parts['UNTIL'];
            $dateStr = strlen($raw) >= 8 ? substr($raw, 0, 8) : $raw;
            $until   = DateTimeImmutable::createFromFormat('Ymd', $dateStr);
            if ($until === false) {
                throw new \InvalidArgumentException("Cannot parse UNTIL date: {$raw}");
            }
            $until = $until->setTime(23, 59, 59);
        }

        $byDay        = [];
        $byNthWeekday = null;
        if (isset($parts['BYDAY'])) {
            [$byDay, $byNthWeekday] = self::parseByday($parts['BYDAY']);
        }

        $byMonth = null;
        if (isset($parts['BYMONTH'])) {
            $byMonth = array_map('intval', explode(',', $parts['BYMONTH']));
        }

        $byMonthDay = null;
        if (isset($parts['BYMONTHDAY'])) {
            $byMonthDay = array_map('intval', explode(',', $parts['BYMONTHDAY']));
            if ($frequency === Frequency::Weekly) {
                throw new \InvalidArgumentException('BYMONTHDAY MUST NOT be used with FREQ=WEEKLY (RFC 5545 §3.3.10)');
            }
            foreach ($byMonthDay as $dom) {
                if ($dom === 0 || $dom < -31 || $dom > 31) {
                    throw new \InvalidArgumentException("BYMONTHDAY value must be 1–31 or -31–-1, got {$dom}");
                }
            }
        }

        return new self($frequency, $interval, $count, $until, $byDay, $byNthWeekday, $byMonth, $byMonthDay, []);
    }

    // -------------------------------------------------------------------------
    // Immutable withers
    // -------------------------------------------------------------------------

    public function onDays(DayName ...$days): self
    {
        return new self(
            $this->frequency,
            $this->interval,
            $this->count,
            $this->until,
            array_values($days),
            null,
            $this->byMonth,
            $this->byMonthDay,
            $this->exDates,
        );
    }

    public function onNthWeekday(int $nth, DayName $day): self
    {
        return new self(
            $this->frequency,
            $this->interval,
            $this->count,
            $this->until,
            [],
            [$nth => $day],
            $this->byMonth,
            $this->byMonthDay,
            $this->exDates,
        );
    }

    public function every(int $interval): self
    {
        return new self(
            $this->frequency,
            $interval,
            $this->count,
            $this->until,
            $this->byDay,
            $this->byNthWeekday,
            $this->byMonth,
            $this->byMonthDay,
            $this->exDates,
        );
    }

    public function limitTo(int $occurrences): self
    {
        if ($occurrences < 1) {
            throw new \InvalidArgumentException("COUNT must be >= 1, got {$occurrences}");
        }
        return new self(
            $this->frequency,
            $this->interval,
            $occurrences,
            null,
            $this->byDay,
            $this->byNthWeekday,
            $this->byMonth,
            $this->byMonthDay,
            $this->exDates,
        );
    }

    public function until(DateTimeImmutable $until): self
    {
        return new self(
            $this->frequency,
            $this->interval,
            null,
            $until->setTime(23, 59, 59),
            $this->byDay,
            $this->byNthWeekday,
            $this->byMonth,
            $this->byMonthDay,
            $this->exDates,
        );
    }

    public function excluding(DateTimeImmutable ...$dates): self
    {
        $exDates = $this->exDates;
        foreach ($dates as $d) {
            $exDates[] = $d->format('Y-m-d');
        }
        return new self(
            $this->frequency,
            $this->interval,
            $this->count,
            $this->until,
            $this->byDay,
            $this->byNthWeekday,
            $this->byMonth,
            $this->byMonthDay,
            array_values(array_unique($exDates)),
        );
    }

    /** Set BYMONTH constraint (month numbers 1–12). */
    public function onMonths(int ...$months): self
    {
        return new self(
            $this->frequency,
            $this->interval,
            $this->count,
            $this->until,
            $this->byDay,
            $this->byNthWeekday,
            array_values($months),
            $this->byMonthDay,
            $this->exDates,
        );
    }

    /** Set BYMONTHDAY constraint (1–31 or -31–-1; negative counts from end of month). */
    public function onMonthDays(int ...$days): self
    {
        if ($this->frequency === Frequency::Weekly) {
            throw new \InvalidArgumentException('BYMONTHDAY MUST NOT be used with FREQ=WEEKLY (RFC 5545 §3.3.10)');
        }
        foreach ($days as $dom) {
            if ($dom === 0 || $dom < -31 || $dom > 31) {
                throw new \InvalidArgumentException("BYMONTHDAY value must be 1–31 or -31–-1, got {$dom}");
            }
        }
        return new self(
            $this->frequency,
            $this->interval,
            $this->count,
            $this->until,
            $this->byDay,
            $this->byNthWeekday,
            $this->byMonth,
            array_values($days),
            $this->exDates,
        );
    }

    // -------------------------------------------------------------------------
    // Expansion
    // -------------------------------------------------------------------------

    /** @return list<DateTimeImmutable> */
    public function expand(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $from  = $from->setTime(0, 0, 0);
        $to    = $to->setTime(23, 59, 59);
        $exSet = array_flip($this->exDates);

        $candidates = $this->generateCandidates($from, $to);

        $results  = [];
        $hitCount = 0;
        foreach ($candidates as $date) {
            if (isset($exSet[$date->format('Y-m-d')])) {
                continue;
            }
            if ($date >= $from && $date <= $to) {
                $results[] = $date->setTime(0, 0, 0);
            }
            $hitCount++;
            if ($this->count !== null && $hitCount >= $this->count) {
                break;
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    public function toRruleString(): string
    {
        $parts = ['FREQ=' . $this->frequency->value];

        if ($this->interval !== 1) {
            $parts[] = 'INTERVAL=' . $this->interval;
        }
        if ($this->count !== null) {
            $parts[] = 'COUNT=' . $this->count;
        }
        if ($this->until !== null) {
            $parts[] = 'UNTIL=' . $this->until->format('Ymd\T235959\Z');
        }
        if ($this->byDay !== []) {
            $parts[] = 'BYDAY=' . implode(',', array_map(
                fn (DayName $d) => self::dayToRruleCode($d),
                $this->byDay,
            ));
        }
        if ($this->byNthWeekday !== null) {
            $segments = [];
            foreach ($this->byNthWeekday as $nth => $day) {
                $segments[] = $nth . self::dayToRruleCode($day);
            }
            $parts[] = 'BYDAY=' . implode(',', $segments);
        }
        if ($this->byMonth !== null) {
            $parts[] = 'BYMONTH=' . implode(',', $this->byMonth);
        }
        if ($this->byMonthDay !== null) {
            $parts[] = 'BYMONTHDAY=' . implode(',', $this->byMonthDay);
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

    /** @return list<DayName> */
    public function getByDay(): array
    {
        return $this->byDay;
    }

    /** @return array<int, DayName>|null */
    public function getByNthWeekday(): ?array
    {
        return $this->byNthWeekday;
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

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return list<DateTimeImmutable> */
    private function generateCandidates(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return match ($this->frequency) {
            Frequency::Daily   => $this->expandDaily($from, $to),
            Frequency::Weekly  => $this->expandWeekly($from, $to),
            Frequency::Monthly => $this->expandMonthly($from, $to),
            Frequency::Yearly  => $this->expandYearly($from, $to),
        };
    }

    /** @return list<DateTimeImmutable> */
    private function expandDaily(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $results = [];
        $current = $from->setTime(0, 0, 0);
        $hits    = 0;

        while ($current <= $to) {
            if ($this->matchesByDay($current) && $this->matchesByMonth($current) && $this->matchesByMonthDay($current)) {
                $results[] = $current;
                $hits++;
                if ($this->count !== null && $hits >= $this->count) {
                    break;
                }
            }
            $current = $current->modify('+' . $this->interval . ' days');
            if ($this->until !== null && $current > $this->until) {
                break;
            }
        }

        return $results;
    }

    /** @return list<DateTimeImmutable> */
    private function expandWeekly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $results    = [];
        $targetDays = $this->byDay !== [] ? $this->byDay : [DayName::fromDate($from)];
        $weekStart  = $from->modify('monday this week')->setTime(0, 0, 0);
        $hits       = 0;

        while ($weekStart <= $to) {
            foreach ($targetDays as $dayName) {
                $candidate = $weekStart->modify(strtolower($dayName->name) . ' this week')->setTime(0, 0, 0);
                if ($candidate < $from || $candidate > $to) {
                    continue;
                }
                if (!$this->matchesByMonth($candidate) || !$this->matchesByMonthDay($candidate)) {
                    continue;
                }
                if ($this->until !== null && $candidate > $this->until) {
                    continue;
                }
                $results[] = $candidate;
                $hits++;
                if ($this->count !== null && $hits >= $this->count) {
                    return $results;
                }
            }
            $weekStart = $weekStart->modify('+' . $this->interval . ' weeks');
        }

        return $results;
    }

    /** @return list<DateTimeImmutable> */
    private function expandMonthly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $results   = [];
        $monthDate = $from->modify('first day of this month')->setTime(0, 0, 0);
        $hits      = 0;

        while ($monthDate <= $to) {
            if ($this->matchesByMonth($monthDate)) {
                foreach ($this->expandMonth($monthDate) as $candidate) {
                    if ($candidate < $from || $candidate > $to) {
                        continue;
                    }
                    if ($this->until !== null && $candidate > $this->until) {
                        break 2;
                    }
                    $results[] = $candidate;
                    $hits++;
                    if ($this->count !== null && $hits >= $this->count) {
                        return $results;
                    }
                }
            }
            $monthDate = $monthDate->modify('+' . $this->interval . ' months')->modify('first day of this month');
        }

        return $results;
    }

    /** @return list<DateTimeImmutable> */
    private function expandMonth(DateTimeImmutable $monthStart): array
    {
        // BYMONTHDAY takes precedence when set — iterate over those specific days
        if ($this->byMonthDay !== null) {
            $results     = [];
            $daysInMonth = (int) $monthStart->format('t');
            foreach ($this->byMonthDay as $dom) {
                // Resolve negative values: -1 = last day, -2 = second-to-last, etc.
                $resolved = $dom >= 0 ? $dom : $daysInMonth + $dom + 1;
                if ($resolved < 1 || $resolved > $daysInMonth) {
                    continue;
                }
                $candidate = $monthStart->setDate(
                    (int) $monthStart->format('Y'),
                    (int) $monthStart->format('m'),
                    $resolved,
                )->setTime(0, 0, 0);
                if ($this->byDay !== [] && !$this->matchesByDay($candidate)) {
                    continue;
                }
                $results[] = $candidate;
            }
            return $results;
        }

        if ($this->byNthWeekday !== null) {
            $results = [];
            foreach ($this->byNthWeekday as $nth => $dayName) {
                $candidate = $this->nthWeekdayInMonth($monthStart, $nth, $dayName);
                if ($candidate !== null) {
                    $results[] = $candidate;
                }
            }
            return $results;
        }

        if ($this->byDay !== []) {
            $results = [];
            $current = $monthStart;
            $lastDay = $monthStart->modify('last day of this month');
            while ($current <= $lastDay) {
                if ($this->matchesByDay($current)) {
                    $results[] = $current;
                }
                $current = $current->modify('+1 day');
            }
            return $results;
        }

        // No BYDAY — same day-of-month
        $dom       = (int) $monthStart->format('d');
        $candidate = $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('m'),
            $dom,
        );
        return $candidate !== false ? [$candidate] : [];
    }

    /** @return list<DateTimeImmutable> */
    private function expandYearly(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $results  = [];
        $yearDate = $from->modify('first day of January this year')->setTime(0, 0, 0);
        $hits     = 0;

        while ($yearDate <= $to) {
            $months = $this->byMonth ?? range(1, 12);
            foreach ($months as $month) {
                $monthStart = $yearDate->setDate((int) $yearDate->format('Y'), $month, 1);
                foreach ($this->expandMonth($monthStart) as $candidate) {
                    if ($candidate < $from || $candidate > $to) {
                        continue;
                    }
                    if ($this->until !== null && $candidate > $this->until) {
                        break 3;
                    }
                    $results[] = $candidate;
                    $hits++;
                    if ($this->count !== null && $hits >= $this->count) {
                        return $results;
                    }
                }
            }
            $yearDate = $yearDate->modify('+' . $this->interval . ' years')->modify('first day of January this year');
        }

        return $results;
    }

    private function matchesByDay(DateTimeImmutable $date): bool
    {
        if ($this->byDay === []) {
            return true;
        }
        return in_array(DayName::fromDate($date), $this->byDay, true);
    }

    private function matchesByMonth(DateTimeImmutable $date): bool
    {
        if ($this->byMonth === null) {
            return true;
        }
        return in_array((int) $date->format('n'), $this->byMonth, true);
    }

    private function matchesByMonthDay(DateTimeImmutable $date): bool
    {
        if ($this->byMonthDay === null) {
            return true;
        }
        $dayOfMonth  = (int) $date->format('j');
        $daysInMonth = (int) $date->format('t');
        foreach ($this->byMonthDay as $dom) {
            $resolved = $dom >= 0 ? $dom : $daysInMonth + $dom + 1;
            if ($resolved === $dayOfMonth) {
                return true;
            }
        }
        return false;
    }

    private function nthWeekdayInMonth(DateTimeImmutable $monthStart, int $nth, DayName $dayName): ?DateTimeImmutable
    {
        $dayLower = strtolower($dayName->name);

        if ($nth > 0) {
            $first  = $monthStart->modify("first {$dayLower} of this month");
            $result = $first->modify('+' . ($nth - 1) . ' weeks');
            if ($result->format('m') !== $monthStart->format('m')) {
                return null;
            }
            return $result->setTime(0, 0, 0);
        }

        if ($nth < 0) {
            $last   = $monthStart->modify("last {$dayLower} of this month");
            $result = $last->modify('+' . ($nth + 1) . ' weeks');
            if ($result->format('m') !== $monthStart->format('m')) {
                return null;
            }
            return $result->setTime(0, 0, 0);
        }

        return null;
    }

    private static function dayToRruleCode(DayName $day): string
    {
        return match ($day) {
            DayName::Monday    => 'MO',
            DayName::Tuesday   => 'TU',
            DayName::Wednesday => 'WE',
            DayName::Thursday  => 'TH',
            DayName::Friday    => 'FR',
            DayName::Saturday  => 'SA',
            DayName::Sunday    => 'SU',
        };
    }

    /** @return array{list<DayName>, array<int, DayName>|null} */
    private static function parseByday(string $byday): array
    {
        $dayMap = [
            'MO' => DayName::Monday, 'TU' => DayName::Tuesday,
            'WE' => DayName::Wednesday, 'TH' => DayName::Thursday,
            'FR' => DayName::Friday, 'SA' => DayName::Saturday, 'SU' => DayName::Sunday,
        ];

        $byDay        = [];
        $byNthWeekday = null;

        foreach (explode(',', $byday) as $token) {
            $token = trim($token);
            if (preg_match('/^(-?\d+)([A-Z]{2})$/', $token, $m)) {
                $code = strtoupper($m[2]);
                if (!isset($dayMap[$code])) {
                    throw new \InvalidArgumentException("Unknown BYDAY day code: {$code}");
                }
                $byNthWeekday ??= [];
                $byNthWeekday[(int) $m[1]] = $dayMap[$code];
            } else {
                $code = strtoupper($token);
                if (!isset($dayMap[$code])) {
                    throw new \InvalidArgumentException("Unknown BYDAY day code: {$code}");
                }
                $byDay[] = $dayMap[$code];
            }
        }

        return [$byDay, $byNthWeekday];
    }
}
