<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: Jozef Môstka
 * Date: 9. 11. 2024
 * Time: 16:02
 */

namespace Tito10047\Calendar;

use Tito10047\Calendar\Interface\EventInterface;

class Event implements EventInterface
{
    public function __construct(
        private \DateTimeImmutable $from,
        private \DateTimeImmutable $to,
        private string $title,
        private string $description,
        private string $status
    ) {
    }

    public function getFrom(): \DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): \DateTimeImmutable
    {
        return $this->to;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
