<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 16:50
 */

namespace Tito10047\Calendar\Renderer;

use Tito10047\Calendar\Interface\DayNameRendererInterface;
use Tito10047\Calendar\Interface\WeekRowRendererInterface;

class MonthRenderer implements \Tito10047\Calendar\Interface\MonthRendererInterface
{
    public function __construct(
        private DayNameRendererInterface $dayNameRenderer,
        private WeekRowRendererInterface $weekRowRenderer
    ) {
    }


    public function renderMonth(\DateTimeImmutable $month, array $headers, array $dayRows): string
    {
        $html = "<table class='calendar'>";
        $html .= "<thead><tr>";
        foreach ($headers as $day) {
            $name = $this->dayNameRenderer->renderDayName($day);
            $html .= "<th class='day-name'>{$name}</th>";
        }
        $html .= "</tr></thead>";
        $html .= "<tbody>";
        foreach ($dayRows as $row) {
            $html .= "<tr class='week'>";
            $html .= $this->weekRowRenderer->renderWeekRow((int)$month->format("n"), ...$row);
            $html .= "</tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";
        return $html;

    }
}
