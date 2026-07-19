<?php

declare(strict_types=1);

namespace Tito10047\Calendar\View;

/** Controls how AgendaView clusters events — by day, week, or month. */
enum AgendaGrouping
{
    case Day;
    case Week;
    case Month;
}
