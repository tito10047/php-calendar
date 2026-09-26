<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Server;

/**
 * Immutable value object representing a CalDAV HTTP response.
 * Returned by CalDavServer handler methods; the framework adapter maps it to a real HTTP response.
 */
final class CalDavResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly string $contentType = 'text/xml; charset=utf-8',
        public readonly array $headers = [],
    ) {
    }
}
