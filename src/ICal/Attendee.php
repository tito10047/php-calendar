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

    /** Unfolded ATTENDEE content line. All values are escaped/quoted — safe for untrusted input. */
    public function toIcalLine(): string
    {
        $params = 'ROLE=' . ICalFormatter::token($this->role, 'REQ-PARTICIPANT')
            . ';PARTSTAT=' . ICalFormatter::token($this->partStat, 'NEEDS-ACTION');
        if ($this->rsvp) {
            $params .= ';RSVP=TRUE';
        }
        if ($this->name !== null) {
            $params .= ';CN=' . ICalFormatter::param($this->name);
        }
        return 'ATTENDEE;' . $params . ':mailto:' . ICalFormatter::value($this->email);
    }
}
