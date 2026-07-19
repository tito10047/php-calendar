<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Enum;

/** RFC 5545 §3.8.1.11 — STATUS property values for VEVENT. */
enum EventStatus: string
{
    case Tentative  = 'TENTATIVE';
    case Confirmed  = 'CONFIRMED';
    case Cancelled  = 'CANCELLED';
}
