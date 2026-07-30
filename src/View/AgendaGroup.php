<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

use DateTimeImmutable;

/**
 * A cluster of agenda entries that share the same day, week, or month.
 */
final class AgendaGroup
{
    /** @param list<AgendaEntry> $entries */
    public function __construct(
        public readonly string $label,
        public readonly DateTimeImmutable $date,
        public readonly array $entries,
    ) {
    }

    /** @return list<AgendaEntry> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
