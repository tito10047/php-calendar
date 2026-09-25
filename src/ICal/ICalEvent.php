<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

use DateTimeImmutable;
use Tito10047\Calendar\Enum\EventClass;
use Tito10047\Calendar\Enum\EventStatus;
use Tito10047\Calendar\Enum\EventTransp;
use Tito10047\Calendar\Recurrence\RecurrenceRule;

/**
 * Parsed representation of a single VEVENT block.
 * Immutable value object.
 *
 * P0 fields: uid, dtStart, dtEnd, summary, description, location, rrule, exDates, url, color, categories, status
 * P1 fields: alarms, organizer, organizerName, attendees
 * P2 fields: extensionProperties (X-*), transp, classification, priority,
 *            dtStamp, created, lastModified, sequence, recurrenceId, modifiedOccurrences
 * v3 fields: allDay (DATE-valued DTSTART), thisAndFuture (RECURRENCE-ID;RANGE=THISANDFUTURE)
 */
final class ICalEvent
{
    /**
     * @param list<DateTimeImmutable>      $exDates
     * @param list<string>                 $categories
     * @param list<VAlarm>                 $alarms
     * @param list<Attendee>               $attendees
     * @param array<string, string>        $extensionProperties  X-* custom properties
     * @param array<string, ICalEvent>     $modifiedOccurrences  RECURRENCE-ID overrides keyed by Y-m-d
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
        // P0.2
        public readonly ?string $color = null,
        public readonly array $categories = [],
        public readonly ?EventStatus $status = null,
        // P1
        public readonly array $alarms = [],
        public readonly ?string $organizer = null,
        public readonly ?string $organizerName = null,
        public readonly array $attendees = [],
        // P2
        public readonly array $extensionProperties = [],
        public readonly ?EventTransp $transp = null,
        public readonly ?EventClass $classification = null,
        public readonly int $priority = 0,
        public readonly ?DateTimeImmutable $dtStamp = null,
        public readonly ?DateTimeImmutable $created = null,
        public readonly ?DateTimeImmutable $lastModified = null,
        public readonly int $sequence = 0,
        public readonly ?DateTimeImmutable $recurrenceId = null,
        public readonly array $modifiedOccurrences = [],
        // All-day (DTSTART;VALUE=DATE) event — dtEnd, when set, is the exclusive end date
        public readonly bool $allDay = false,
        // RECURRENCE-ID;RANGE=THISANDFUTURE — this override also applies to all later instances
        public readonly bool $thisAndFuture = false,
    ) {
    }

    public function isRecurring(): bool
    {
        return $this->rrule !== null;
    }

    /**
     * Exclusive end of the event starting at $start (the DTSTART by default), or null when the
     * event has no DTEND/DURATION. All-day durations are nominal (whole days), timed durations
     * are exact (elapsed seconds), per RFC 5545 §3.3.6.
     */
    public function getEnd(?DateTimeImmutable $start = null): ?DateTimeImmutable
    {
        if ($this->dtEnd === null) {
            return null;
        }
        if ($start === null) {
            return $this->dtEnd;
        }
        if ($this->allDay) {
            $days = (int) $this->dtStart->setTime(0, 0, 0)->diff($this->dtEnd->setTime(0, 0, 0))->format('%r%a');
            return $start->modify("{$days} days");
        }
        return $start->setTimestamp($start->getTimestamp() + $this->dtEnd->getTimestamp() - $this->dtStart->getTimestamp());
    }

    /**
     * Start times of every instance that overlaps the days [from, to] (both inclusive).
     *
     * Includes recurring occurrences, RDATEs and RECURRENCE-ID overrides (at their moved-to
     * time); EXDATEs are removed. Instances that started before $from but are still running
     * are included. Use expandOccurrences() to get the full per-instance ICalEvent objects.
     *
     * Day boundaries are taken in $from's timezone for timed events and in the event's own
     * timezone for all-day (DATE) events, so an all-day event always lands on its calendar date.
     *
     * @return list<DateTimeImmutable>
     */
    public function occurrences(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_map(static fn (ICalEvent $e) => $e->dtStart, $this->expandOccurrences($from, $to));
    }

    /**
     * Expand to one ICalEvent per instance overlapping the days [from, to] (both inclusive).
     *
     * Generated instances carry the instance's own DTSTART/DTEND, have RECURRENCE-ID set to the
     * original start and no RRULE of their own. Modified occurrences (RECURRENCE-ID, including
     * RANGE=THISANDFUTURE) replace their base occurrence.
     *
     * @return list<ICalEvent> sorted by start
     */
    public function expandOccurrences(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        [$rangeStart, $rangeEnd] = $this->rangeBounds($from, $to);

        if ($this->rrule === null) {
            return $this->overlaps($this, $rangeStart, $rangeEnd) ? [$this] : [];
        }

        // Look back far enough to catch instances that started before the range but still run into it
        $span       = $this->getEnd($this->dtStart) ?? $this->dtStart;
        $lookBack   = $rangeStart->setTimestamp($rangeStart->getTimestamp() - max(0, $span->getTimestamp() - $this->dtStart->getTimestamp()));
        $expandFrom = $lookBack->setTimezone($rangeStart->getTimezone())->setTime(0, 0, 0);
        $expandTo   = $rangeEnd->modify('-1 second');

        $rule = $this->rrule;
        if ($this->exDates !== []) {
            $rule = $rule->excluding(...$this->exDates);
        }

        $futureOverrides = array_values(array_filter(
            $this->modifiedOccurrences,
            static fn (ICalEvent $o) => $o->thisAndFuture && $o->recurrenceId !== null,
        ));
        usort($futureOverrides, static fn (ICalEvent $a, ICalEvent $b) => $a->recurrenceId <=> $b->recurrenceId);

        $result = [];
        $used   = [];
        foreach ($rule->expand($expandFrom, $expandTo, $this->dtStart) as $start) {
            $key = $start->format('Y-m-d');
            if (isset($this->modifiedOccurrences[$key])) {
                $instance   = $this->modifiedOccurrences[$key];
                $used[$key] = true;
            } else {
                $instance = $this->instanceAt($start, $futureOverrides);
            }
            if ($this->overlaps($instance, $rangeStart, $rangeEnd)) {
                $result[] = $instance;
            }
        }

        // Overrides moved into the range from an original date outside it
        foreach ($this->modifiedOccurrences as $key => $override) {
            if (!isset($used[$key]) && $this->overlaps($override, $rangeStart, $rangeEnd)) {
                $result[] = $override;
            }
        }

        usort($result, static fn (ICalEvent $a, ICalEvent $b) => $a->dtStart <=> $b->dtStart);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Withers
    // -------------------------------------------------------------------------

    public function withAlarm(VAlarm $alarm): self
    {
        $alarms   = $this->alarms;
        $alarms[] = $alarm;
        return $this->clone(alarms: $alarms);
    }

    public function withOrganizer(string $email, ?string $name = null): self
    {
        return $this->clone(organizer: $email, organizerName: $name);
    }

    public function withAttendee(Attendee $attendee): self
    {
        $attendees   = $this->attendees;
        $attendees[] = $attendee;
        return $this->clone(attendees: $attendees);
    }

    /**
     * Attach a RECURRENCE-ID override to this master event.
     * $originalDate is the original start of the occurrence being replaced; overrides are keyed
     * by its calendar date in the master event's timezone.
     */
    public function withModifiedOccurrence(DateTimeImmutable $originalDate, ICalEvent $replacement): self
    {
        $overrides                                  = $this->modifiedOccurrences;
        $overrides[$this->occurrenceKey($originalDate)] = $replacement;
        return $this->clone(modifiedOccurrences: $overrides);
    }

    /** Copy with a different start / end (e.g. converted to another timezone). */
    public function withDates(DateTimeImmutable $dtStart, ?DateTimeImmutable $dtEnd): self
    {
        return $this->clone(dtStart: $dtStart, dtEnd: $dtEnd);
    }

    public function getExtendedProperty(string $name): ?string
    {
        return $this->extensionProperties[strtoupper($name)] ?? null;
    }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'uid'                => $this->uid,
            'summary'            => $this->summary,
            'description'        => $this->description,
            'location'           => $this->location,
            'url'                => $this->url,
            'color'              => $this->color,
            'categories'         => $this->categories,
            'status'             => $this->status?->value,
            'transp'             => $this->transp?->value,
            'classification'     => $this->classification?->value,
            'priority'           => $this->priority,
            'sequence'           => $this->sequence,
            'allDay'             => $this->allDay,
            'dtStart'            => $this->dtStart->format('Y-m-d H:i:s'),
            'dtEnd'              => $this->dtEnd?->format('Y-m-d H:i:s'),
            'dtStamp'            => $this->dtStamp?->format('Y-m-d H:i:s'),
            'created'            => $this->created?->format('Y-m-d H:i:s'),
            'lastModified'       => $this->lastModified?->format('Y-m-d H:i:s'),
            'rrule'              => $this->rrule?->toRruleString(),
            'exDates'            => array_map(fn ($d) => $d->format('Y-m-d H:i:s'), $this->exDates),
            'organizer'          => $this->organizer,
            'organizerName'      => $this->organizerName,
            'recurrenceId'       => $this->recurrenceId?->format('Y-m-d H:i:s'),
            'extensionProperties' => $this->extensionProperties,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Clone self with specific field overrides — avoids huge constructor repetition in withers.
     *
     * @param list<DateTimeImmutable>|null  $exDates
     * @param list<string>|null             $categories
     * @param list<VAlarm>|null             $alarms
     * @param list<Attendee>|null           $attendees
     * @param array<string, string>|null    $extensionProperties
     * @param array<string, ICalEvent>|null $modifiedOccurrences
     */
    private function clone(
        ?string $uid = null,
        ?DateTimeImmutable $dtStart = null,
        mixed $dtEnd = 'KEEP',
        mixed $summary = 'KEEP',
        mixed $description = 'KEEP',
        mixed $location = 'KEEP',
        mixed $rrule = 'KEEP',
        ?array $exDates = null,
        mixed $url = 'KEEP',
        mixed $color = 'KEEP',
        ?array $categories = null,
        mixed $status = 'KEEP',
        ?array $alarms = null,
        mixed $organizer = 'KEEP',
        mixed $organizerName = 'KEEP',
        ?array $attendees = null,
        ?array $extensionProperties = null,
        mixed $transp = 'KEEP',
        mixed $classification = 'KEEP',
        ?int $priority = null,
        mixed $dtStamp = 'KEEP',
        mixed $created = 'KEEP',
        mixed $lastModified = 'KEEP',
        ?int $sequence = null,
        mixed $recurrenceId = 'KEEP',
        ?array $modifiedOccurrences = null,
    ): self {
        return new self(
            uid:                  $uid ?? $this->uid,
            dtStart:              $dtStart ?? $this->dtStart,
            dtEnd:                $dtEnd === 'KEEP' ? $this->dtEnd : $dtEnd,
            summary:              $summary === 'KEEP' ? $this->summary : $summary,
            description:          $description === 'KEEP' ? $this->description : $description,
            location:             $location === 'KEEP' ? $this->location : $location,
            rrule:                $rrule === 'KEEP' ? $this->rrule : $rrule,
            exDates:              array_values($exDates ?? $this->exDates),
            url:                  $url === 'KEEP' ? $this->url : $url,
            color:                $color === 'KEEP' ? $this->color : $color,
            categories:           array_values($categories ?? $this->categories),
            status:               $status === 'KEEP' ? $this->status : $status,
            alarms:               array_values($alarms ?? $this->alarms),
            organizer:            $organizer === 'KEEP' ? $this->organizer : $organizer,
            organizerName:        $organizerName === 'KEEP' ? $this->organizerName : $organizerName,
            attendees:            array_values($attendees ?? $this->attendees),
            extensionProperties:  $extensionProperties ?? $this->extensionProperties,
            transp:               $transp === 'KEEP' ? $this->transp : $transp,
            classification:       $classification === 'KEEP' ? $this->classification : $classification,
            priority:             $priority ?? $this->priority,
            dtStamp:              $dtStamp === 'KEEP' ? $this->dtStamp : $dtStamp,
            created:              $created === 'KEEP' ? $this->created : $created,
            lastModified:         $lastModified === 'KEEP' ? $this->lastModified : $lastModified,
            sequence:             $sequence ?? $this->sequence,
            recurrenceId:         $recurrenceId === 'KEEP' ? $this->recurrenceId : $recurrenceId,
            modifiedOccurrences:  $modifiedOccurrences ?? $this->modifiedOccurrences,
            allDay:               $this->allDay,
            thisAndFuture:        $this->thisAndFuture,
        );
    }

    /**
     * Build the generated instance starting at $start, applying the latest RANGE=THISANDFUTURE
     * override that precedes it (if any).
     *
     * @param list<ICalEvent> $futureOverrides sorted by recurrenceId
     */
    private function instanceAt(DateTimeImmutable $start, array $futureOverrides): self
    {
        $template = null;
        foreach ($futureOverrides as $override) {
            if ($override->recurrenceId !== null && $override->recurrenceId <= $start) {
                $template = $override;
            }
        }

        if ($template === null || $template->recurrenceId === null) {
            return $this->clone(
                dtStart:             $start,
                dtEnd:               $this->getEnd($start),
                rrule:               null,
                exDates:             [],
                recurrenceId:        $start,
                modifiedOccurrences: [],
            );
        }

        // Shift by the same offset the THISANDFUTURE override applied to its own instance
        $shifted = $template->allDay
            ? $start->modify((int) $template->recurrenceId->setTime(0, 0, 0)->diff($template->dtStart->setTime(0, 0, 0))->format('%r%a') . ' days')
            : $start->setTimestamp($start->getTimestamp() + $template->dtStart->getTimestamp() - $template->recurrenceId->getTimestamp());

        return $template->clone(
            dtStart:             $shifted,
            dtEnd:               $template->getEnd($shifted),
            rrule:               null,
            exDates:             [],
            recurrenceId:        $start,
            modifiedOccurrences: [],
        );
    }

    /**
     * Day range [start, end) for occurrence queries. All-day events use wall-clock days in the
     * event's own timezone so they never drift to a neighbouring date.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function rangeBounds(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($this->allDay) {
            $tz    = $this->dtStart->getTimezone();
            $start = new DateTimeImmutable($from->format('Y-m-d'), $tz);
            $end   = (new DateTimeImmutable($to->format('Y-m-d'), $tz))->modify('+1 day');
            return [$start, $end];
        }
        return [$from->setTime(0, 0, 0), $to->setTime(0, 0, 0)->modify('+1 day')];
    }

    private function overlaps(ICalEvent $instance, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): bool
    {
        [$start, $end] = [$instance->dtStart, $instance->getEnd($instance->dtStart)];
        if ($instance->allDay !== $this->allDay) {
            // Override switched between all-day and timed — compare on the override's own terms
            [$rangeStart, $rangeEnd] = $instance->rangeBounds($rangeStart, $rangeEnd->modify('-1 second'));
        }
        if ($end === null || $end <= $start) {
            return $start >= $rangeStart && $start < $rangeEnd;
        }
        return $start < $rangeEnd && $end > $rangeStart;
    }

    private function occurrenceKey(DateTimeImmutable $originalStart): string
    {
        return $this->allDay
            ? $originalStart->format('Y-m-d')
            : $originalStart->setTimezone($this->dtStart->getTimezone())->format('Y-m-d');
    }
}
