<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Interface;

interface DayDataLoaderInterface
{
    public function load(\DateTimeImmutable $from, \DateTimeImmutable $to): void;

    /**
     * @return array<mixed>
     */
    public function getData(\DateTimeImmutable $date): array;
}
