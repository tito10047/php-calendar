<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Interface;

use DateTimeImmutable;
use Tito10047\Calendar\Day;
use Tito10047\Calendar\Enum\DayName;

interface CalendarInterface
{
    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getDate(): DateTimeImmutable;

    /** @return array{from: DateTimeImmutable, to: DateTimeImmutable} */
    public function getDateRange(): array;

    public function getStartDay(): DayName;

    /**
     * Date-specific disabled dates only (not name-pattern disabled days).
     * @return list<DateTimeImmutable>
     */
    public function getDisabledDays(): array;

    /**
     * Structural weekday pattern — days disabled for every period.
     * These survive nextPeriod() / prevPeriod() navigation.
     * @return list<DayName>
     */
    public function getDisabledDayNames(): array;

    /**
     * Dates that override a disabledDayNames rule for a specific occurrence.
     * @return list<DateTimeImmutable>
     */
    public function getEnabledDays(): array;

    public function isDayDisabled(DateTimeImmutable|Day $day): bool;

    public function isFirstDay(\DateTimeInterface|Day $day): bool;

    public function isLastDay(\DateTimeInterface|Day $day): bool;

    // -------------------------------------------------------------------------
    // Calendar grid
    // -------------------------------------------------------------------------

    /** @return array<int, array<int, Day>> */
    public function getDaysTable(): array;

    // -------------------------------------------------------------------------
    // Date navigation
    // -------------------------------------------------------------------------

    /**
     * Return a new instance for a different reference date.
     * All settings (generator, startDay, all disable/enable lists, dataLoader) are preserved.
     */
    public function withDate(DateTimeImmutable $date): self;

    /**
     * Advance one period (month for Monthly, week for Weekly/WorkWeek).
     * Structural disabledDayNames survive; date-specific disabledDays and enabledDays reset.
     */
    public function nextPeriod(): self;

    /**
     * Retreat one period. Same reset semantics as nextPeriod().
     */
    public function prevPeriod(): self;

    // -------------------------------------------------------------------------
    // Disable / enable mutations
    // -------------------------------------------------------------------------

    /**
     * Disable specific dates. Removes them from enabledDays if present.
     */
    public function disableDays(DateTimeImmutable ...$days): self;

    /**
     * Re-enable specific dates.
     * Removes the date from the date-specific disabled list; adds it to enabledDays
     * so it overrides any disabledDayNames rule that would otherwise disable it.
     */
    public function enableDays(DateTimeImmutable ...$days): self;

    /**
     * Disable all days matching the given weekday names (structural rule).
     * Stored as DayName[], not concrete dates — survives period navigation.
     */
    public function disableDaysByName(DayName ...$daysToDisable): self;

    /**
     * Disable all dates within [from, to] inclusive.
     * Null defaults to the first/last day of the current grid.
     */
    public function disableDaysRange(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): self;

    /**
     * Disable all days in the given ISO week number.
     */
    public function disableWeek(int $weekNum): self;
}
