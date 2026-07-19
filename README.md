# php-calendar

[![PHP Tests](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml/badge.svg)](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/tito10047/php-calendar)](LICENSE)

**Pure PHP calendar library. Zero dependencies. Built for server-side rendering.**

Feed it a date. Get back a typed `Day[][]` table. Build your own template.
Import an `.ics` file. Serve a JSON feed to FullCalendar. Sync via CalDAV. All in one package.

---

## Why this library?

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
// array<int weekNumber, array<int isoDay 1–7, Day>>
```

```twig
<table class="calendar">
    <tbody>
        {% for week in table %}
            <tr>
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
$occurrences = $events[0]->expandOccurrences($from, $to); // list<ICalEvent>
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
    echo $group->getLabel();           // "Monday, 14 July 2025"
    foreach ($group->getEvents() as $event) {
        echo $event->dtStart->format('H:i') . ' ' . $event->summary;
    }
}
```

---

## Day / time-slot view

```php
use Tito10047\Calendar\View\DayView;

$slots = DayView::forDate(new DateTimeImmutable('2025-06-15'))
    ->withSlotDuration(30)   // minutes
    ->withRange(8, 20)        // 08:00 – 20:00
    ->setEvents($events)
    ->getSlots();

foreach ($slots as $slot) {
    // $slot->startTime, $slot->endTime, $slot->events[]
}
```

---

## CalDAV — fetch from a server

```php
use Tito10047\Calendar\ICal\CalDAVClient;

$events = (new CalDAVClient('https://cloud.example.com/remote.php/dav/calendars/jan/personal/'))
    ->authenticate('jan', 'app-password')
    ->fetchEvents(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'));
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
RecurrenceRule::weekly()->onDays(DayName::Tuesday)->every(2)->count(10);

// 15th and last day of each month
RecurrenceRule::monthly()->onMonthDays(15, -1);

// Parse from iCal string
$rule = RecurrenceRule::fromRrule('FREQ=WEEKLY;BYDAY=MO,WE,FR;WKST=SU');
```

---

## The `Day` object

```php
$day->date      // DateTimeImmutable
$day->ghost     // belongs to adjacent month (Monthly grid padding only)
$day->today     // matches today's date
$day->enabled   // not disabled by any rule
$day->data      // ?array — whatever your DayDataLoaderInterface returned
```

---

## Calendar types and factories

```php
Calendar::forMonth(2024, 11)                          // full month, aligned to complete weeks
Calendar::forWeek(new DateTimeImmutable('2024-11-04')) // one ISO week
Calendar::forToday(CalendarType::WorkWeek)            // this work week
Calendar::fromDateRange($from, $to)                   // arbitrary date range
```

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

## License

MIT
