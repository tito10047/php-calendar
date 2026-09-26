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
 * K1 fields: allDay
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
     *
     * $allDay marks a DATE-valued event (RFC 5545 §3.3.4): no time, no timezone.
     * Its $dtEnd — like DTEND in the format itself — is exclusive, so a one-day
     * event that starts on 2026-09-25 ends on 2026-09-26.
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
        // K1
        public readonly bool $allDay = false,
    ) {
    }

    /**
     * The last day the event covers, for an all-day event whose DTEND is exclusive.
     * Returns null for timed events and for all-day events without an end.
     */
    public function lastDay(): ?DateTimeImmutable
    {
        if (!$this->allDay || $this->dtEnd === null) {
            return null;
        }

        return $this->dtEnd->modify('-1 day')->setTime(0, 0, 0);
    }

    public function isRecurring(): bool
    {
        return $this->rrule !== null;
    }

    /**
     * Expand to concrete occurrence dates within [from, to].
     * Overridden occurrences (RECURRENCE-ID) are excluded — use expandOccurrences() for full objects.
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
        // Treat overridden occurrences like EXDATE (they'll be added back via expandOccurrences)
        if ($this->modifiedOccurrences !== []) {
            foreach (array_keys($this->modifiedOccurrences) as $overriddenKey) {
                $dt   = DateTimeImmutable::createFromFormat('Y-m-d', $overriddenKey);
                if ($dt !== false) {
                    $rule = $rule->excluding($dt);
                }
            }
        }

        return $rule->expand($from, $to);
    }

    /**
     * Expand to full ICalEvent objects for each occurrence in [from, to].
     * Modified occurrences (RECURRENCE-ID) replace their base occurrence with the override event.
     *
     * @return list<ICalEvent>
     */
    public function expandOccurrences(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dates  = $this->occurrences($from, $to);
        $result = [];
        $tz     = $this->dtStart->getTimezone();

        foreach ($dates as $date) {
            $key = $date->format('Y-m-d');
            if (isset($this->modifiedOccurrences[$key])) {
                $result[] = $this->modifiedOccurrences[$key];
            } else {
                // Reconstruct occurrence: keep original time, shift to occurrence date, preserve TZ
                $newStart = $date->setTimezone($tz)->setTime(
                    (int) $this->dtStart->format('H'),
                    (int) $this->dtStart->format('i'),
                    (int) $this->dtStart->format('s'),
                );
                $newEnd = $this->dtEnd !== null
                    ? $newStart->add($this->dtStart->diff($this->dtEnd))
                    : null;
                $result[] = $this->withDates($newStart, $newEnd);
            }
        }

        // Add override events whose moved-to date falls in range but whose original date is outside
        foreach ($this->modifiedOccurrences as $override) {
            $os = $override->dtStart->setTime(0, 0, 0);
            if ($os >= $from->setTime(0, 0, 0) && $os <= $to->setTime(23, 59, 59)) {
                if (!in_array($override, $result, true)) {
                    $result[] = $override;
                }
            }
        }

        usort($result, fn (ICalEvent $a, ICalEvent $b) => $a->dtStart <=> $b->dtStart);

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
     * $originalDate is the Y-m-d of the occurrence being replaced.
     */
    public function withModifiedOccurrence(DateTimeImmutable $originalDate, ICalEvent $replacement): self
    {
        $overrides                                    = $this->modifiedOccurrences;
        $overrides[$originalDate->format('Y-m-d')] = $replacement;
        return $this->clone(modifiedOccurrences: $overrides);
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
        ?bool $allDay = null,
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
            allDay:               $allDay ?? $this->allDay,
        );
    }

    private function withDates(DateTimeImmutable $newStart, ?DateTimeImmutable $newEnd): self
    {
        return $this->clone(dtStart: $newStart, dtEnd: $newEnd);
    }
}
