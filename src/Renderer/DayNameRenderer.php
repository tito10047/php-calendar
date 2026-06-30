<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 16:19
 */

namespace Tito10047\Calendar\Renderer;

use Symfony\Contracts\Translation\TranslatorInterface;
use Tito10047\Calendar\Enum\DayName;

class DayNameRenderer implements \Tito10047\Calendar\Interface\DayNameRendererInterface
{
    public function __construct(
        private TranslatorInterface $translator,
        private string $translationDomain,
        private array $dayNameClasses = ["day-name"]
    ) {
    }


    public function renderDayName(DayName $day): string
    {
        $name = $day->getShortName();
        $name = $this->translator->trans($name, [], $this->translationDomain);
        $classes = join(" ", $this->dayNameClasses);
        return <<<HTML
            <span class="{$classes}">{$name}</span>
            HTML;

    }
}
