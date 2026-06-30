<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Parsed representation of a single VEVENT block.
 * Immutable value object.
 */
final class ICalEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly DateTimeImmutable $dtStart,
        public readonly ?DateTimeImmutable $dtEnd,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly ?RecurrenceRule $rrule,
        /** @var list<DateTimeImmutable> */
        public readonly array $exDates = [],
    ) {
    }

    /**
     * Whether this event repeats.
     */
    public function isRecurring(): bool
    {
        return $this->rrule !== null;
    }

    /**
     * Expand this event into all concrete occurrences within [from, to].
     * Non-recurring events return their dtStart if it falls in range.
     *
     * @return list<DateTimeImmutable>
     */
    public function occurrences(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($this->rrule === null) {
            $start = $this->dtStart->setTime(0, 0, 0);
            if ($start >= $from->setTime(0, 0, 0) && $start <= $to->setTime(23, 59, 59)) {
                return [$start];
            }
            return [];
        }

        $rule = $this->rrule;
        if ($this->exDates !== []) {
            $rule = $rule->excluding(...$this->exDates);
        }

        return $rule->expand($from, $to);
    }

    /**
     * Serialize to a plain array — useful for caching or passing to external systems.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid'         => $this->uid,
            'summary'     => $this->summary,
            'description' => $this->description,
            'location'    => $this->location,
            'dtStart'     => $this->dtStart->format('Y-m-d H:i:s'),
            'dtEnd'       => $this->dtEnd?->format('Y-m-d H:i:s'),
        ];
    }
}
