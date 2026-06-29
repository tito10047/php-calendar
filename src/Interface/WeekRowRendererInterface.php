<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 15:55
 */

namespace Tito10047\Calendar\Interface;

interface WeekRowRendererInterface
{
    public function renderWeekRow(int $month, \Tito10047\Calendar\Day ...$days): string;
}
