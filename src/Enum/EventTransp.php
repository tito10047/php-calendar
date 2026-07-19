<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Enum;

/** RFC 5545 §3.8.2.7 — TRANSP property: whether an event blocks time in scheduling. */
enum EventTransp: string
{
    case Opaque      = 'OPAQUE';
    case Transparent = 'TRANSPARENT';
}
