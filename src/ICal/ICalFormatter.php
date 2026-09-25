<?php

declare(strict_types=1);

namespace Tito10047\Calendar\ICal;

/**
 * RFC 5545 serialisation primitives shared by ICalExporter, Attendee and VAlarm.
 *
 * Every value written to an .ics stream goes through one of these helpers so that user input
 * can never break out of its property (CR/LF injection) or parameter (';' / ':' injection).
 *
 * @internal
 */
final class ICalFormatter
{
    /** Escape a TEXT value (RFC 5545 §3.3.11). Control characters other than TAB are removed. */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /** Sanitise a non-TEXT value (URI, CAL-ADDRESS, DURATION …): strip all control characters. */
    public static function value(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
    }

    /**
     * Encode a parameter value: RFC 6868 caret escapes for newlines and DQUOTE, and DQUOTE
     * quoting when the value contains ';', ':' or ','.
     */
    public static function param(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace(['^', "\n", '"'], ['^^', '^n', "^'"], $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        return preg_match('/[;:,]/', $value) ? '"' . $value . '"' : $value;
    }

    /** Restrict a token (property name, ROLE, PARTSTAT, ACTION …) to [A-Z0-9-]. */
    public static function token(string $value, string $fallback): string
    {
        $token = (string) preg_replace('/[^A-Z0-9-]/', '', strtoupper($value));
        return $token !== '' ? $token : $fallback;
    }

    /**
     * Fold content lines at 75 octets (RFC 5545 §3.1) without splitting UTF-8 sequences.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    public static function fold(array $lines): array
    {
        $folded = [];
        foreach ($lines as $line) {
            $limit = 75;
            while (strlen($line) > $limit) {
                $cut = $limit;
                // Back off to the start of a UTF-8 character (continuation bytes are 10xxxxxx)
                while ($cut > 1 && (ord($line[$cut]) & 0xC0) === 0x80) {
                    $cut--;
                }
                $folded[] = substr($line, 0, $cut);
                $line     = ' ' . substr($line, $cut);
                $limit    = 75;
            }
            $folded[] = $line;
        }
        return $folded;
    }
}
