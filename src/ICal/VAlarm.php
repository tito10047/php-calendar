<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

/** RFC 5545 VALARM component. */
final class VAlarm
{
    public function __construct(
        public readonly string $action,
        public readonly string $trigger,
        public readonly ?string $description = null,
        public readonly ?string $summary = null,
    ) {
    }

    /** Display alarm: show description at trigger time. */
    public static function display(string $trigger, string $description): self
    {
        return new self(action: 'DISPLAY', trigger: $trigger, description: $description);
    }

    /** Email alarm: send email at trigger time. */
    public static function email(string $trigger, string $summary, ?string $description = null): self
    {
        return new self(action: 'EMAIL', trigger: $trigger, description: $description, summary: $summary);
    }

    /** Audio alarm: play sound at trigger time. */
    public static function audio(string $trigger): self
    {
        return new self(action: 'AUDIO', trigger: $trigger);
    }

    /** CRLF-joined VALARM block (unfolded). All values are escaped — safe for untrusted input. */
    public function toIcalLines(): string
    {
        $lines = [
            'BEGIN:VALARM',
            'ACTION:' . ICalFormatter::token($this->action, 'DISPLAY'),
            'TRIGGER:' . ICalFormatter::value($this->trigger),
        ];
        if ($this->description !== null) {
            $lines[] = 'DESCRIPTION:' . ICalFormatter::text($this->description);
        }
        if ($this->summary !== null) {
            $lines[] = 'SUMMARY:' . ICalFormatter::text($this->summary);
        }
        $lines[] = 'END:VALARM';
        return implode("\r\n", $lines);
    }
}
