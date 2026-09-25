<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 21:20
 */

namespace Tito10047\Calendar;

final class Day
{
    /**
     * @param array<mixed>|null $data
     */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly bool $ghost,
        public readonly bool $today,
        public readonly bool $enabled,
        public readonly ?array $data = null
    ) {
    }

    /** ISO 8601 week number (1–53). */
    public function getIsoWeek(): int
    {
        return (int) $this->date->format('W');
    }

    /** ISO 8601 week-numbering year (may differ from the calendar year around New Year). */
    public function getIsoWeekYear(): int
    {
        return (int) $this->date->format('o');
    }

    public function getDayName(): Enum\DayName
    {
        return Enum\DayName::fromDate($this->date);
    }

    /**
     * @param array<mixed> $data
     */
    public function withData(array $data): self
    {
        return new self(
            $this->date,
            $this->ghost,
            $this->today,
            $this->enabled,
            $data
        );
    }
}
