<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 16:45
 */

namespace Tito10047\Calendar\Renderer;

class WeekRowRenderer implements \Tito10047\Calendar\Interface\WeekRowRendererInterface
{
    public function __construct(
        private \Tito10047\Calendar\Interface\DayRendererInterface $dayRenderer,
    ) {
    }


    public function renderWeekRow(int $month, \Tito10047\Calendar\Day ...$days): string
    {
        $html = "<tr>";
        foreach ($days as $day) {
            $classes = [];
            if ($day->ghost) {
                $classes[] = "ghost";
            }
            if ($day->today) {
                $classes[] = "today";
            }
            if (!$day->enabled) {
                $classes[] = "disabled";
            }
            $classes = implode(" ", $classes);
            $html .= "<td class='{$classes}'>";
            $html .= $this->dayRenderer->renderDay($day->date, []);
            $html .= "</td>";
        }
        $html .= "</tr>";
        return $html;
    }
}
