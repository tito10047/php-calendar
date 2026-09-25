# Agenda view and day / time-slot view

Beyond the month/week grid, the library provides two additional views for displaying events:

- **AgendaView** — chronological list grouped by day, week, or month. Ideal for sidebars, mobile UI, email digests.
- **DayView** — hourly time-slot grid for a single day. Ideal for booking systems, appointment planners, clinic schedulers.

---

## AgendaView

### Basic usage

```php
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\View\AgendaView;
use Tito10047\Calendar\View\AgendaGrouping;

$events = (new ICalParser())->parseUrl('https://example.com/calendar.ics');

$groups = AgendaView::fromEvents($events)
    ->forRange(new DateTimeImmutable('today'), new DateTimeImmutable('+30 days'))
    ->groupBy(AgendaGrouping::Day)
    ->getGroups();

foreach ($groups as $group) {
    echo $group->label;        // e.g. "Monday, 14 July 2025"
    echo $group->date->format('Y-m-d');

    foreach ($group->getEntries() as $entry) {   // list<AgendaEntry>, also $group->entries
        $event = $entry->event;                  // ICalEvent — this instance's own dtStart/dtEnd
        echo $event->dtStart->format('H:i') . ' ' . $event->summary;
    }
}
```

`AgendaGroup` has public readonly `label`, `date` and `entries` (plus `getEntries()`).
`AgendaEntry` has public readonly `event` (`ICalEvent`) and `date` (the day it is listed under).

### Grouping options

| Constant | Groups by | Label example |
|----------|-----------|---------------|
| `AgendaGrouping::Day` | each day | "Monday, 14 July 2025" |
| `AgendaGrouping::Week` | week (starts Monday, change with `withWeekStart()`) | "14 Jul 2025 – 20 Jul 2025" |
| `AgendaGrouping::Month` | calendar month | "July 2025" |

```php
use Tito10047\Calendar\Enum\WeekStart;

AgendaView::fromEvents($events)
    ->groupBy(AgendaGrouping::Week)
    ->withWeekStart(WeekStart::Sunday)   // "13 Jul 2025 – 19 Jul 2025"
    ->getGroups();
```

### Twig example — sidebar agenda

```twig
<aside class="agenda">
    {% for group in agenda %}
        <div class="agenda-group">
            <h3 class="agenda-date">{{ group.label }}</h3>
            {% for entry in group.entries %}
                {% set event = entry.event %}
                <div class="agenda-event {{ event.status ? event.status.value|lower : '' }}"
                     style="border-left: 3px solid {{ event.color ?? '#3788d8' }}">
                    <time>{{ event.allDay ? 'All day' : event.dtStart|date('H:i') }}</time>
                    <strong>{{ event.summary }}</strong>
                    {% if event.location %}
                        <span class="location">{{ event.location }}</span>
                    {% endif %}
                </div>
            {% endfor %}
        </div>
    {% endfor %}
</aside>
```

### How it works

`AgendaView` calls `expandOccurrences($from, $to)` on each event, so recurring events are expanded
and RECURRENCE-ID overrides are applied automatically. Events are sorted chronologically within each group
(all-day entries first within a day).

Timed events are shown in the timezone of the `$from` date passed to `forRange()`; all-day events keep their
calendar date. Events already running when the range starts (e.g. a multi-day event) are listed on the first day of the range.

---

## DayView — hourly time-slot grid

### Basic usage

```php
use Tito10047\Calendar\View\DayView;

$view = DayView::forDate(new DateTimeImmutable('2025-06-15', new DateTimeZone('Europe/Bratislava')))
    ->withSlotDuration(30)    // minutes per slot (must divide 60 evenly)
    ->withRange(8, 20)         // 08:00 – 20:00
    ->setEvents($events);

foreach ($view->getAllDayEvents() as $event) {   // all-day events are NOT in any slot
    echo 'All day: ' . $event->summary;
}

foreach ($view->getSlots() as $slot) {
    echo $slot->startTime->format('H:i') . ' – ' . $slot->endTime->format('H:i');
    foreach ($slot->events as $event) {
        echo '  ' . $event->summary;
    }
}
```

The view works in the **timezone of the date passed to `forDate()`**: timed events are converted to it,
recurring events are expanded (RECURRENCE-ID overrides applied), and overnight / multi-day events fill every
slot they overlap. Events in slots are per-instance `ICalEvent`s carrying the occurrence's own start / end.
All-day events are reported only by `getAllDayEvents()` — render them in a separate header row.

Slots are measured in elapsed time, so on DST-change days a full-day view has 23 or 25 hourly slots.

### Slot duration

Valid values: any positive divisor of 60 — 1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60 minutes.

```php
->withSlotDuration(15)   // 15-minute slots: 08:00, 08:15, 08:30, …
->withSlotDuration(60)   // hourly slots
```

### TimeSlot object

```php
$slot->startTime; // DateTimeImmutable — slot start
$slot->endTime;   // DateTimeImmutable — slot end (exclusive)
$slot->events;    // list<ICalEvent> — timed event instances overlapping this slot
$slot->isEmpty(); // bool
```

An event appears in a slot when its time range overlaps the slot: `event.start < slot.end && event.end > slot.start`.

### Twig example — appointment grid

```twig
<div class="day-view">
    {% for event in allDayEvents %}   {# view.getAllDayEvents() #}
        <div class="all-day">{{ event.summary }}</div>
    {% endfor %}
    {% for slot in slots %}
        <div class="time-slot {{ slot.events ? 'has-events' : 'empty' }}">
            <span class="time">{{ slot.startTime|date('H:i') }}</span>
            <div class="slot-content">
                {% for event in slot.events %}
                    <div class="appointment" style="background: {{ event.color ?? '#3788d8' }}">
                        <strong>{{ event.summary }}</strong>
                        {% if event.location %}
                            <span>{{ event.location }}</span>
                        {% endif %}
                    </div>
                {% endfor %}
            </div>
        </div>
    {% endfor %}
</div>
```

---

## fromDateRange() — arbitrary calendar range

When you need a calendar grid that does not align to a month or week boundary, use `Calendar::fromDateRange()`:

```php
use Tito10047\Calendar\Calendar;

// "Next 30 days" calendar
$from = new DateTimeImmutable('today');
$to   = new DateTimeImmutable('+30 days');

$calendar = Calendar::fromDateRange($from, $to)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday)
    ->setDataLoader($loader);

$table = $calendar->getDaysTable();
// Day[][] — no ghost cells, no padding, just the days in [from, to]
// (rows keyed by ISO year*100 + week, split on the calendar's WeekStart)
```

`fromDateRange()` uses `DateRangeGenerator` internally. The generator:
- never produces ghost days (`hasGhostDays()` returns `false`)
- reports the exact range length as its navigation step

Navigation via `nextPeriod()` / `prevPeriod()` shifts by the same number of days,
so a "next 30 days" pattern works naturally:

```php
$next30 = $calendar->nextPeriod(); // the following block of the same length
$prev30 = $calendar->prevPeriod();
$other  = $calendar->withDate(new DateTimeImmutable('2025-03-01')); // same-length window starting 1 March
```
