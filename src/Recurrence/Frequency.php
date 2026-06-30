<?php

declare(strict_types=1);

namespace Tito10047\Calendar\Recurrence;

enum Frequency: string
{
    case Daily   = 'DAILY';
    case Weekly  = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case Yearly  = 'YEARLY';
}
