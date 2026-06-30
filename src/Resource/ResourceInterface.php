<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Resource;

/**
 * Represents a bookable resource: a room, person, vehicle, or any entity
 * that has per-day availability data in a resource calendar.
 */
interface ResourceInterface
{
    public function getResourceId(): string;

    public function getResourceName(): string;
}
