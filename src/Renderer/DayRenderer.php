<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 16:34
 */

namespace Tito10047\Calendar\Renderer;

use Symfony\Contracts\Translation\TranslatorInterface;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Interface\EventInterface;
use Tito10047\Calendar\Interface\EventRendererInterface;

class DayRenderer implements \Tito10047\Calendar\Interface\DayRendererInterface
{
    public function __construct(
        private EventRendererInterface $eventRenderer,
        private CalendarType $type,
        private ?TranslatorInterface $translator = null,
        private ?string $translationDomain = null,
    ) {
    }


    public function renderDay(\DateTimeImmutable $date, array $events): string
    {
        $dayName = $this->type->getDayName($date, $this->translator, $this->translationDomain);
        $eventsHtml = '';
        if (count($events) > 0) {
            $eventsHtml = '<li class="events">';
            $eventsHtml .= join("\n", array_map(function (EventInterface $event) use ($date) {
                return $this->eventRenderer->renderEvent($this->type, $date, $event);
            }, $events));
            $eventsHtml .= '</li>';
        }
        return <<<HTML
            <div class="day">
                <span class="name">{$dayName}</span>
                {$eventsHtml}
            </div>
            HTML;

    }
}
