<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 10. 11. 2024
 * Time: 7:57
 */

namespace Tito10047\Calendar;

use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Interface\CalendarInterface;
use Tito10047\Calendar\Interface\MonthRendererInterface;
use Tito10047\Calendar\Renderer\DayNameRenderer;
use Tito10047\Calendar\Renderer\DayRenderer;
use Tito10047\Calendar\Renderer\EventRenderer;
use Tito10047\Calendar\Renderer\MonthRenderer;
use Tito10047\Calendar\Renderer\WeekRowRenderer;

final class Renderer
{
    public function __construct(
        private readonly MonthRendererInterface $monthRenderer,
    ) {
    }

    public static function factory(CalendarType $type, string $translationDomain): self
    {
        $translator = new Translator();
        $eventRenderer = new EventRenderer(
            $translator,
            $translationDomain
        );
        $dayNameRenderer = new DayNameRenderer(
            $translator,
            $translationDomain
        );
        $dayRenderer = new DayRenderer(
            $eventRenderer,
            $type,
            $translator,
            $translationDomain
        );
        $weekRowRenderer = new WeekRowRenderer(
            $dayRenderer
        );
        $monthRenderer = new MonthRenderer(
            $dayNameRenderer,
            $weekRowRenderer
        );

        return new self(
            $monthRenderer,
        );
    }


    public function render(CalendarInterface $calendar): string
    {
        $daysTable = $calendar->getDaysTable();
        return $this->monthRenderer->renderMonth(
            $calendar->getDate(),
            DayName::all($calendar->getStartDay()),
            $daysTable
        );
    }

}
