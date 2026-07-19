<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;

/** RFC 5545 VTODO component — represents a task or to-do item. */
final class ICalTodo
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?DateTimeImmutable $due,
        public readonly ?DateTimeImmutable $dtStart,
        public readonly string $status = 'NEEDS-ACTION',
        public readonly int $priority = 0,
        public readonly int $percentComplete = 0,
    ) {
    }

    public function isCompleted(): bool
    {
        return strtoupper($this->status) === 'COMPLETED';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'uid'             => $this->uid,
            'summary'         => $this->summary,
            'description'     => $this->description,
            'due'             => $this->due?->format('Y-m-d H:i:s'),
            'dtStart'         => $this->dtStart?->format('Y-m-d H:i:s'),
            'status'          => $this->status,
            'priority'        => $this->priority,
            'percentComplete' => $this->percentComplete,
        ];
    }
}
