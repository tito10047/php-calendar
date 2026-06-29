<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 16:44
 */

namespace Tito10047\Calendar\Interface;

use Tito10047\Calendar\Enum\DayName;

interface MonthRendererInterface
{
    /**
     * @param \DateTimeImmutable $month
     * @param DayName[] $headers
     * @param \Tito10047\Calendar\Day[][] ...$dayRows
     * @return string
     */
    public function renderMonth(\DateTimeImmutable $month, array $headers, array $dayRows): string;
}
