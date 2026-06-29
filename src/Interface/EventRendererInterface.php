<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 18:51
 */

namespace Tito10047\Calendar\Interface;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\CalendarType;

interface EventRendererInterface
{
    public function renderEvent(CalendarType $type, DateTimeImmutable $date, EventInterface $event): string;
}
