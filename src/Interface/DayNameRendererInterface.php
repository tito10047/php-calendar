<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 15:55
 */

namespace Tito10047\Calendar\Interface;

use Tito10047\Calendar\Enum\DayName;

interface DayNameRendererInterface
{
    public function renderDayName(DayName $day): string;
}
