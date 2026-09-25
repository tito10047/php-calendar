# php-calendar

[![PHP Tests](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml/badge.svg)](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/tito10047/php-calendar)](LICENSE)

**Pure PHP calendar library. Zero dependencies. Built for server-side rendering.**

Feed it a date. Get back a typed `Day[][]` table. Build your own template.
Import an `.ics` file. Serve a JSON feed to FullCalendar. Sync via CalDAV. All in one package.

---

## Why this library?

- Works with **Symfony UX Live components** / **Livewire** — no hydration, no client state sync
- **Server-side grid rendering** — monthly, weekly, work-week, or any custom date range
- **RFC 5545 iCal** — full parse/export: RRULE, EXDATE, RDATE, VALARM, ORGANIZER, ATTENDEE, RECURRENCE-ID, X-* props, CalDAV metadata
- **JSON feed** — one call produces FullCalendar / Toast UI / DHTMLX-compatible output
- **Agenda & time-slot views** — chronological list and hourly day grid, no extra packages
- **CalDAV client** — fetch events directly from Nextcloud, iCloud, Fastmail, Google Calendar
- **Fully immutable** — every mutation returns a new instance, safe to share and cache
- Zero frontend dependencies — just your own HTML and CSS

---

## Installation

```bash
composer require tito10047/php-calendar
```

Requires PHP 8.2+. No other dependencies.

---

## Quick start — calendar grid

```php
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\DayName;

$calendar = Calendar::forMonth(2024, 11)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday)
    ->setDataLoader(new MyEventLoader());

$table = $calendar->getDaysTable();
// array<int yearWeek, array<int isoDay 1–7, Day>>
// outer key = ISO year * 100 + ISO week of the row's Monday (e.g. 202445) — unique across years,
// chronological, json_encode-safe; rows start on the configured WeekStart, inner keys follow that order
```

```twig
<table class="calendar">
    <tbody>
        {% for week in table %}
            <tr>
                <th class="week-number">{{ (week|first).isoWeek }}</th>
                {% for day in week %}
                    <td class="{{ day.ghost ? 'ghost' : '' }} {{ day.today ? 'today' : '' }} {{ day.enabled ? '' : 'disabled' }}">
                        {% if not day.ghost %}
                            <span>{{ day.date|date('j') }}</span>
                            {% for event in day.data ?? [] %}
                                <div class="event" style="background: {{ event.color }}">
                                    {{ event.summary }}
                                </div>
                            {% endfor %}
                        {% endif %}
                    </td>
                {% endfor %}
            </tr>
        {% endfor %}
    </tbody>
</table>
```

---

## iCal import

```php
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\ICal\ICalDataLoader;

$events = (new ICalParser())->parseUrl('https://calendar.google.com/.../basic.ics');

// Attach to the grid — recurring events expanded automatically
$calendar = Calendar::forMonth(2024, 11)
    ->setDataLoader(ICalDataLoader::fromEvents($events));

// Or expand occurrences for a custom range (RECURRENCE-ID overrides applied)
$occurrences = $events[0]->expandOccurrences($from, $to); // list<ICalEvent>, one per instance
$starts      = $events[0]->occurrences($from, $to);       // list<DateTimeImmutable> instance start datetimes
```

---

## JSON feed for FullCalendar

```php
use Tito10047\Calendar\Serializer\JsonSerializer;

$events = (new ICalParser())->parseUrl('https://example.com/calendar.ics');

header('Content-Type: application/json');
echo JsonSerializer::fromEvents($events)
    ->forRange(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'))
    ->toJson();
```

Output is compatible with [FullCalendar](https://fullcalendar.io), Toast UI Calendar and DHTMLX Scheduler.

---

## Agenda / list view

```php
use Tito10047\Calendar\View\AgendaView;
use Tito10047\Calendar\View\AgendaGrouping;

$groups = AgendaView::fromEvents($events)
    ->forRange(new DateTimeImmutable('today'), new DateTimeImmutable('+30 days'))
    ->groupBy(AgendaGrouping::Day)
    ->getGroups();

foreach ($groups as $group) {
    echo $group->label;                // "Monday, 14 July 2025"
    foreach ($group->getEntries() as $entry) {
        echo $entry->event->dtStart->format('H:i') . ' ' . $entry->event->summary;
    }
}
```

---

## Day / time-slot view

```php
use Tito10047\Calendar\View\DayView;

$view = DayView::forDate(new DateTimeImmutable('2025-06-15')) // works in this date's timezone
    ->withSlotDuration(30)   // minutes
    ->withRange(8, 20)        // 08:00 – 20:00
    ->setEvents($events);

$allDay = $view->getAllDayEvents(); // all-day events are not placed in slots

foreach ($view->getSlots() as $slot) {
    // $slot->startTime, $slot->endTime, $slot->events[] (timed instances overlapping the slot)
}
```

---

## CalDAV — fetch from a server

```php
use Tito10047\Calendar\ICal\CalDAVClient;

$events = (new CalDAVClient('https://cloud.example.com/remote.php/dav/calendars/jan/personal/'))
    ->authenticate('jan', 'app-password')
    ->fetchEvents(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
// throws RuntimeException on HTTP errors (e.g. 401), cross-origin redirects or malformed responses
```

---

## Recurring events (RRULE)

```php
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\Enum\DayName;

// Last working day of every month
RecurrenceRule::monthly()
    ->onDays(DayName::Monday, DayName::Tuesday, DayName::Wednesday, DayName::Thursday, DayName::Friday)
    ->bySetPos(-1);

// Every other Tuesday, max 10 times
RecurrenceRule::weekly()->onDays(DayName::Tuesday)->every(2)->limitTo(10);

// 15th and last day of each month
RecurrenceRule::monthly()->onMonthDays(15, -1);

// Parse from iCal string
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR;WKST=SU');

// Expand — anchored at DTSTART (COUNT/INTERVAL counted from it, its time and timezone kept)
$starts = $rule->expand($from, $to, dtStart: new DateTimeImmutable('2025-01-06 09:00'));
```

---

## The `Day` object

```php
$day->date      // DateTimeImmutable
$day->ghost     // belongs to adjacent month (Monthly grid padding only)
$day->today     // matches today's date
$day->enabled   // not disabled by any rule
$day->data      // ?array — whatever your DayDataLoaderInterface returned

$day->getIsoWeek()     // ISO week number (1–53)
$day->getIsoWeekYear() // ISO week-numbering year
$day->getDayName()     // DayName enum
```

---

## Calendar types and factories

```php
Calendar::forMonth(2024, 11)                          // full month, aligned to complete weeks
Calendar::forMonth(2024, 11, WeekStart::Sunday, new DateTimeZone('America/New_York'))
Calendar::forWeek(new DateTimeImmutable('2024-11-04')) // one week
Calendar::forToday(CalendarType::WorkWeek)            // this work week ("today" in the given/default timezone)
Calendar::fromDateRange($from, $to)                   // arbitrary date range (navigable, moves by its own length)
```

The `today` flag is evaluated in the calendar's timezone (the timezone of its reference date).

---

## Further reading

| Topic | File |
|-------|------|
| Disable / enable model (three layers) | [docs/disable-model.md](docs/disable-model.md) |
| Attaching custom data to days | [docs/data-loading.md](docs/data-loading.md) |
| Navigation, caching, CalendarConfig | [docs/navigation.md](docs/navigation.md) |
| Recurring events (RRULE, BYSETPOS, BYMONTHDAY, RDATE, WKST) | [docs/recurrence.md](docs/recurrence.md) |
| iCal import, export, VALARM, ORGANIZER, RECURRENCE-ID, X-* | [docs/ical.md](docs/ical.md) |
| JSON feed for FullCalendar / Toast UI | [docs/json-feed.md](docs/json-feed.md) |
| Agenda view and day / time-slot view | [docs/views.md](docs/views.md) |
| CalDAV client (Nextcloud, iCloud, Google) | [docs/caldav.md](docs/caldav.md) |
| Resource calendar (rooms, people, vehicles) | [docs/resource-calendar.md](docs/resource-calendar.md) |
| Booking system pattern | [docs/booking-mode.md](docs/booking-mode.md) |

---

## Running tests

```bash
composer install
vendor/bin/phpunit
```

CI runs across PHP 8.2–8.5 on every push.

---

## Upgrading

Behaviour changes to be aware of when upgrading to 3.0:

- **`getDaysTable()` keys** — outer keys are now `ISO year * 100 + ISO week` of the row's Monday (e.g. `202445`), not the bare week number. Use `$key % 100`, `Day::getIsoWeek()` or Twig `week|first.isoWeek` for the week number. Rows always start on the configured `WeekStart`.
- **`DayDataLoaderInterface::load()` returns `static`** — return `$this` (or a new loaded instance) instead of `void`; the calendar calls `getData()` on the returned object.
- **`RecurrenceRule` is anchored at DTSTART** — pass it as `expand($from, $to, $dtStart)`; without it the series is anchored at midnight of `$from`. `count()` does not exist — use `limitTo()`. Invalid rules throw `InvalidArgumentException`.
- **`ICalEvent::occurrences()` returns start datetimes** (with the event's time and timezone), not midnight dates, and includes instances overlapping the range.
- **JSON feed** — timed `start`/`end` include the UTC offset (`2025-06-01T10:00:00+00:00`); `allDay` comes from `ICalEvent::$allDay`, not from a midnight heuristic; all-day `end` is exclusive.
- **`DayView`** — all-day events are no longer in time slots; use `getAllDayEvents()`. The view uses the timezone of the date passed to `forDate()`.
- **`AgendaView`** — use `$group->label` / `$group->getEntries()` and `$entry->event`; week labels read "14 Jul 2025 – 20 Jul 2025".
- **`CalDAVClient` throws** `RuntimeException` on non-2xx responses, cross-origin redirects and malformed XML instead of returning an empty list; credentials over plain `http://` require `allowInsecureHttp()`.

---

## License

MIT
