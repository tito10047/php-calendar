<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Enum;

/** RFC 5545 §3.8.1.3 — CLASS property: event visibility/access classification. */
enum EventClass: string
{
    case Public       = 'PUBLIC';
    case Private      = 'PRIVATE';
    case Confidential = 'CONFIDENTIAL';
}
