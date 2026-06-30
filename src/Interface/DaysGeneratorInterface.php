<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 10. 11. 2024
 * Time: 8:10
 */

namespace Tito10047\Calendar\Interface;

use Tito10047\Calendar\Enum\DayName;

interface DaysGeneratorInterface
{
    /**
     * @return list<\DateTimeImmutable>
     */
    public function getDays(\DateTimeImmutable $day, DayName $firstDay): array;

    /**
     * Whether days outside the primary period (e.g. adjacent-month padding) should be flagged as ghost.
     * Return false for week-based generators where every day in the range is a first-class cell.
     */
    public function hasGhostDays(): bool;

    /**
     * How far to advance/retreat when calling Calendar::nextPeriod() / prevPeriod().
     * Monthly generators return P1M; week-based generators return P7D.
     */
    public function getNavigationStep(): \DateInterval;
}
