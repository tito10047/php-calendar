<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\EventStatus;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Parsed representation of a single VEVENT block.
 * Immutable value object.
 */
final class ICalEvent
{
    /**
     * @param list<DateTimeImmutable> $exDates
     * @param list<string>            $categories
     * @param list<VAlarm>            $alarms
     * @param list<Attendee>          $attendees
     */
    public function __construct(
        public readonly string $uid,
        public readonly DateTimeImmutable $dtStart,
        public readonly ?DateTimeImmutable $dtEnd,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly ?RecurrenceRule $rrule,
        public readonly array $exDates = [],
        public readonly ?string $url = null,
        public readonly ?string $color = null,
        public readonly array $categories = [],
        public readonly ?EventStatus $status = null,
        public readonly array $alarms = [],
        public readonly ?string $organizer = null,
        public readonly ?string $organizerName = null,
        public readonly array $attendees = [],
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
     * Return a copy with an additional alarm.
     */
    public function withAlarm(VAlarm $alarm): self
    {
        $alarms   = $this->alarms;
        $alarms[] = $alarm;
        return new self(
            uid:           $this->uid,
            dtStart:       $this->dtStart,
            dtEnd:         $this->dtEnd,
            summary:       $this->summary,
            description:   $this->description,
            location:      $this->location,
            rrule:         $this->rrule,
            exDates:       $this->exDates,
            url:           $this->url,
            color:         $this->color,
            categories:    $this->categories,
            status:        $this->status,
            alarms:        $alarms,
            organizer:     $this->organizer,
            organizerName: $this->organizerName,
            attendees:     $this->attendees,
        );
    }

    /**
     * Return a copy with an organizer.
     */
    public function withOrganizer(string $email, ?string $name = null): self
    {
        return new self(
            uid:           $this->uid,
            dtStart:       $this->dtStart,
            dtEnd:         $this->dtEnd,
            summary:       $this->summary,
            description:   $this->description,
            location:      $this->location,
            rrule:         $this->rrule,
            exDates:       $this->exDates,
            url:           $this->url,
            color:         $this->color,
            categories:    $this->categories,
            status:        $this->status,
            alarms:        $this->alarms,
            organizer:     $email,
            organizerName: $name,
            attendees:     $this->attendees,
        );
    }

    /**
     * Return a copy with an additional attendee.
     */
    public function withAttendee(Attendee $attendee): self
    {
        $attendees   = $this->attendees;
        $attendees[] = $attendee;
        return new self(
            uid:           $this->uid,
            dtStart:       $this->dtStart,
            dtEnd:         $this->dtEnd,
            summary:       $this->summary,
            description:   $this->description,
            location:      $this->location,
            rrule:         $this->rrule,
            exDates:       $this->exDates,
            url:           $this->url,
            color:         $this->color,
            categories:    $this->categories,
            status:        $this->status,
            alarms:        $this->alarms,
            organizer:     $this->organizer,
            organizerName: $this->organizerName,
            attendees:     $attendees,
        );
    }

    /**
     * Serialize to a plain array — useful for caching or passing to external systems.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid'           => $this->uid,
            'summary'       => $this->summary,
            'description'   => $this->description,
            'location'      => $this->location,
            'url'           => $this->url,
            'color'         => $this->color,
            'categories'    => $this->categories,
            'status'        => $this->status?->value,
            'dtStart'       => $this->dtStart->format('Y-m-d H:i:s'),
            'dtEnd'         => $this->dtEnd?->format('Y-m-d H:i:s'),
            'rrule'         => $this->rrule?->toRruleString(),
            'exDates'       => array_map(fn ($d) => $d->format('Y-m-d H:i:s'), $this->exDates),
            'organizer'     => $this->organizer,
            'organizerName' => $this->organizerName,
        ];
    }
}
