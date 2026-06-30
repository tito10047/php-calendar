<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Recurrence;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\DayName;

/**
 * Immutable value object representing an RFC 5545 RRULE.
 *
 * Supported rule parts: FREQ, INTERVAL, COUNT, UNTIL, BYDAY, BYMONTH, BYSETPOS.
 *
 * Build via static factories:
 *   RecurrenceRule::daily()
 *   RecurrenceRule::weekly()
 *   RecurrenceRule::monthly()
 *   RecurrenceRule::yearly()
 *   RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR')
 *
 * Then constrain via immutable wither methods:
 *   ->onDays(DayName::Monday, DayName::Friday)
 *   ->onNthWeekday(1, DayName::Monday)   // first Monday of month
 *   ->every(2)                           // interval
 *   ->count(10)
 *   ->until(new DateTimeImmutable('2024-12-31'))
 *
 * Finally expand to concrete occurrences:
 *   $dates = $rule->expand($from, $to);  // DateTimeImmutable[]
 */
final class RecurrenceRule
{
    /** @var list<DayName> */
    private readonly array $byDay;

    /** @var array<int, DayName>|null nth-weekday pairs [n => DayName], null = not set */
    private readonly ?array $byNthWeekday;

    /** @var list<int>|null BYMONTH month numbers 1-12 */
    private readonly ?array $byMonth;

    /** @var list<string> EXDATE exclusion dates in Y-m-d format */
    private readonly array $exDates;

    /**
     * @param list<DayName>                $byDay
     * @param array<int, DayName>|null     $byNthWeekday
     * @param list<int>|null               $byMonth
     * @param list<string>                 $exDates
     */
    private function __construct(
        private readonly Frequency $frequency,
        private readonly int $interval,
        private readonly ?int $count,
        private readonly ?DateTimeImmutable $until,
        array $byDay,
        ?array $byNthWeekday,
        ?array $byMonth,
        array $exDates,
    ) {
        $this->byDay        = $byDay;
        $this->byNthWeekday = $byNthWeekday;
        $this->byMonth      = $byMonth;
        $this->exDates      = $exDates;
    }

    // -------------------------------------------------------------------------
    // Static factories
    // -------------------------------------------------------------------------

    public static function daily(): self
    {
        return new self(Frequency::Daily, 1, null, null, [], null, null, []);
    }

    public static function weekly(): self
    {
        return new self(Frequency::Weekly, 1, null, null, [], null, null, []);
    }

    public static function monthly(): self
    {
        return new self(Frequency::Monthly, 1, null, null, [], null, null, []);
    }

    public static function yearly(): self
    {
        return new self(Frequency::Yearly, 1, null, null, [], null, null, []);
    }

    /**
     * Parse a raw RRULE string (with or without the "RRULE:" prefix).
     *
     * Supports: FREQ, INTERVAL, COUNT, UNTIL, BYDAY, BYMONTH.
     */
    public static function fromRrule(string $rrule): self
    {
        $rrule = ltrim($rrule, 'RRULE:');
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
            $raw = $parts['UNTIL'];
            // Normalise: 20241231T000000Z or 20241231
            $dateStr = strlen($raw) >= 8 ? substr($raw, 0, 8) : $raw;
            $until = DateTimeImmutable::createFromFormat('Ymd', $dateStr);
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

        return new self($frequency, $interval, $count, $until, $byDay, $byNthWeekday, $byMonth, []);
    }

    // -------------------------------------------------------------------------
    // Immutable withers
    // -------------------------------------------------------------------------

    public function onDays(DayName ...$days): self
    {
        return new self(
            $this->frequency, $this->interval, $this->count, $this->until,
            array_values($days), null, $this->byMonth, $this->exDates,
        );
    }

    /** nth = 1 for first, -1 for last, etc. */
    public function onNthWeekday(int $nth, DayName $day): self
    {
        return new self(
            $this->frequency, $this->interval, $this->count, $this->until,
            [], [$nth => $day], $this->byMonth, $this->exDates,
        );
    }

    public function every(int $interval): self
    {
        return new self(
            $this->frequency, $interval, $this->count, $this->until,
            $this->byDay, $this->byNthWeekday, $this->byMonth, $this->exDates,
        );
    }

    public function count(int $count): self
    {
        return new self(
            $this->frequency, $this->interval, $count, null,
            $this->byDay, $this->byNthWeekday, $this->byMonth, $this->exDates,
        );
    }

    public function until(DateTimeImmutable $until): self
    {
        return new self(
            $this->frequency, $this->interval, null, $until->setTime(23, 59, 59),
            $this->byDay, $this->byNthWeekday, $this->byMonth, $this->exDates,
        );
    }

    /** Add exclusion dates (EXDATE). Can be called multiple times. */
    public function excluding(DateTimeImmutable ...$dates): self
    {
        $exDates = $this->exDates;
        foreach ($dates as $d) {
            $exDates[] = $d->format('Y-m-d');
        }
        return new self(
            $this->frequency, $this->interval, $this->count, $this->until,
            $this->byDay, $this->byNthWeekday, $this->byMonth, array_unique($exDates),
        );
    }

    // -------------------------------------------------------------------------
    // Expansion
    // -------------------------------------------------------------------------

    /**
     * Expand the rule to all concrete occurrences within [from, to] inclusive.
     *
     * @return list<DateTimeImmutable>
     */
    public function expand(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $from  = $from->setTime(0, 0, 0);
        $to    = $to->setTime(23, 59, 59);
        $exSet = array_flip($this->exDates);

        $candidates = $this->generateCandidates($from, $to);

        $results = [];
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
    // Serialisation helpers
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

        return implode(';', $parts);
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

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Generate all candidate dates from the rule's logical start up to $to.
     * We start from a generous window before $from to handle nth-weekday cases
     * that may begin outside the requested range.
     *
     * @return list<DateTimeImmutable>
     */
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
        $step    = 'P' . $this->interval . 'D';
        $hits    = 0;

        while ($current <= $to) {
            if ($this->matchesByDay($current) && $this->matchesByMonth($current)) {
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
        $results = [];
        // Walk week by week, check each day in the BYDAY list
        $targetDays = $this->byDay !== [] ? $this->byDay : [DayName::fromDate($from)];

        // Start from the Monday of the week containing $from
        $weekStart = $from->modify('monday this week')->setTime(0, 0, 0);
        $hits      = 0;

        while ($weekStart <= $to) {
            foreach ($targetDays as $dayName) {
                $candidate = $weekStart->modify(strtolower($dayName->name) . ' this week')->setTime(0, 0, 0);
                if ($candidate < $from || $candidate > $to) {
                    continue;
                }
                if (!$this->matchesByMonth($candidate)) {
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
            $occurrences = $this->expandMonth($monthDate);
            foreach ($occurrences as $candidate) {
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
            $monthDate = $monthDate->modify('+' . $this->interval . ' months')->modify('first day of this month');
        }

        return $results;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function expandMonth(DateTimeImmutable $monthStart): array
    {
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

        // No BYDAY — just the same day-of-month
        $dom = (int) $monthStart->format('d');
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
        $dayName = DayName::fromDate($date);
        return in_array($dayName, $this->byDay, true);
    }

    private function matchesByMonth(DateTimeImmutable $date): bool
    {
        if ($this->byMonth === null) {
            return true;
        }
        return in_array((int) $date->format('n'), $this->byMonth, true);
    }

    private function nthWeekdayInMonth(DateTimeImmutable $monthStart, int $nth, DayName $dayName): ?DateTimeImmutable
    {
        $dayLower = strtolower($dayName->name);

        if ($nth > 0) {
            // nth occurrence from the start of the month
            $first = $monthStart->modify("first {$dayLower} of this month");
            $result = $first->modify('+' . ($nth - 1) . ' weeks');
            // Ensure the result is still within the same month
            if ($result->format('m') !== $monthStart->format('m')) {
                return null;
            }
            return $result->setTime(0, 0, 0);
        }

        if ($nth < 0) {
            // nth occurrence from the end of the month
            $last = $monthStart->modify("last {$dayLower} of this month");
            $result = $last->modify('+' . ($nth + 1) . ' weeks');
            if ($result->format('m') !== $monthStart->format('m')) {
                return null;
            }
            return $result->setTime(0, 0, 0);
        }

        return null;
    }

    /**
     * Parse a BYDAY string into byDay list and byNthWeekday map.
     *
     * @return array{list<DayName>, array<int, DayName>|null}
     */
    private static function parseByday(string $byday): array
    {
        $dayMap = [
            'MO' => DayName::Monday,
            'TU' => DayName::Tuesday,
            'WE' => DayName::Wednesday,
            'TH' => DayName::Thursday,
            'FR' => DayName::Friday,
            'SA' => DayName::Saturday,
            'SU' => DayName::Sunday,
        ];

        $byDay        = [];
        $byNthWeekday = null;

        foreach (explode(',', $byday) as $token) {
            $token = trim($token);
            // Match optional leading integer (nth) followed by 2-char day code
            if (preg_match('/^(-?\d+)([A-Z]{2})$/', $token, $m)) {
                $nth = (int) $m[1];
                $code = strtoupper($m[2]);
                if (!isset($dayMap[$code])) {
                    throw new \InvalidArgumentException("Unknown BYDAY day code: {$code}");
                }
                $byNthWeekday ??= [];
                $byNthWeekday[$nth] = $dayMap[$code];
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
