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
     * Clone current calendar with new date of the next month. It will return new instance of the calendar.
     * Disable days will be cleared
     * @return self
     */
    public function nextMonth(): self;

    /**
     * Clone current calendar with new date of the previous month. It will return new instance of the calendar.
     * Disable days will be cleared
     * @return self
     */
    public function prevMonth(): self;

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
