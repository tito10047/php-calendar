<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

/** RFC 5545 ATTENDEE property. */
final class Attendee
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name = null,
        public readonly string $role = 'REQ-PARTICIPANT',
        public readonly string $partStat = 'NEEDS-ACTION',
        public readonly bool $rsvp = false,
    ) {
    }

    public static function required(string $email, ?string $name = null): self
    {
        return new self(email: $email, name: $name, role: 'REQ-PARTICIPANT');
    }

    public static function optional(string $email, ?string $name = null): self
    {
        return new self(email: $email, name: $name, role: 'OPT-PARTICIPANT');
    }

    public function toIcalLine(): string
    {
        $params = 'ROLE=' . $this->role . ';PARTSTAT=' . $this->partStat;
        if ($this->rsvp) {
            $params .= ';RSVP=TRUE';
        }
        if ($this->name !== null) {
            $params .= ';CN=' . $this->name;
        }
        return 'ATTENDEE;' . $params . ':mailto:' . $this->email;
    }
}
