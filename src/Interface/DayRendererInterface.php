<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 15:54
 */

namespace Tito10047\Calendar\Interface;

interface DayRendererInterface
{
    /**
     * @param \DateTimeImmutable $date
     * @param EventInterface[] $events
     * @return string
     */
    public function renderDay(\DateTimeImmutable $date, array $events): string;
}
