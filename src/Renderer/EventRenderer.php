<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 18:51
 */

namespace Tito10047\Calendar\Renderer;

use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Interface\EventInterface;
use Tito10047\Calendar\Interface\EventRendererInterface;

class EventRenderer implements EventRendererInterface
{
    public function __construct(
        private TranslatorInterface $translator,
        private string $translateDomain
    ) {
    }

    public function renderEvent(CalendarType $type, DateTimeImmutable $date, EventInterface $event): string
    {
        $title = $this->translator->trans($event->getTitle(), [], $this->translateDomain);
        $fromHour = $event->getFrom()->format('H:i');
        $fromMinute = $event->getFrom()->format('i');
        $toHour = $event->getTo()->format('H:i');
        $toMinute = $event->getTo()->format('i');

        return <<<HTML
            <li class="event">
                <span class="from">{$fromHour}<sup>{$fromMinute}</sup></span>
                <span class="to">{$toHour}<sup>{$toMinute}</sup></span>
                <span class="title">{$title}</span>
            </li>
            HTML;

    }
}
