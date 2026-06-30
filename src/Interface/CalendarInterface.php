<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 10. 11. 2024
 * Time: 8:23
 */

namespace Tito10047\Calendar\Interface;

use DateTimeImmutable;
use Tito10047\Calendar\Day;
use Tito10047\Calendar\Enum\DayName;

interface CalendarInterface
{
    public function getDate(): DateTimeImmutable;

    /**
     * Disable days in range
     * @param DateTimeImmutable|null $from date from which to disable days if null it will disable from the first day of current calendar
     * @param DateTimeImmutable|null $to date to which to disable days if null it will disable to the last day of current calendar
     * @return self
     */
    public function disableDaysRange(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null
    ): self;

    /**
     * Disable specific days
     * @param DateTimeImmutable ...$days
     * @return self
     */
    public function disableDays(DateTimeImmutable ...$days): self;

    /**
     * Re-enable previously disabled days. Inverse of disableDays().
     * @param DateTimeImmutable ...$days
     * @return self
     */
    public function enableDays(DateTimeImmutable ...$days): self;

    /**
     * Disable specific days by name, like Monday, Tuesday, etc. use DayName enum
     * @param DayName ...$daysToDisable
     * @return self
     */
    public function disableDaysByName(DayName ...$daysToDisable): self;

    /**
     * Disable specific week in current calendar
     * @param int $weekNum
     * @return self
     */
    public function disableWeek(int $weekNum): self;

    /**
     * Return a new instance with a different reference date, keeping all other settings (generator, startDay, disabledDays, dataLoader).
     */
    public function withDate(DateTimeImmutable $date): self;

    /**
     * Clone current calendar with new date of the next month. It will return new instance of the calendar.
     * Disabled days will be cleared.
     * @return self
     */
    public function nextMonth(): self;

    /**
     * Clone current calendar with new date of the previous month. It will return new instance of the calendar.
     * Disabled days will be cleared.
     * @return self
     */
    public function prevMonth(): self;

    /**
     * Advance by one period as defined by the generator (month for Monthly, week for Weekly/WorkWeek).
     * Disabled days are cleared; all other settings are preserved.
     */
    public function nextPeriod(): self;

    /**
     * Retreat by one period as defined by the generator (month for Monthly, week for Weekly/WorkWeek).
     * Disabled days are cleared; all other settings are preserved.
     */
    public function prevPeriod(): self;

    /**
     * Return the exact date range covered by this calendar instance.
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function getDateRange(): array;

    /**
     * Return array of days in current calendar. Days are grouped by weeks indexed by week number. Days in week are indexed by day number
     * @return array<int,array<int,Day>>
     */
    public function getDaysTable(): array;

    /**
     * Check if day is disabled
     * @param DateTimeImmutable|Day $day
     * @return bool
     */
    public function isDayDisabled(DateTimeImmutable|Day $day): bool;

    /**
     * Get start day of the week
     * @return DayName
     */
    public function getStartDay(): DayName;

    /**
     * Return array of disabled days
     * @return list<DateTimeImmutable>
     */
    public function getDisabledDays(): array;

    public function isFirstDay(\DateTimeInterface|Day $day): bool;

    public function isLastDay(\DateTimeInterface|Day $day): bool;
}
