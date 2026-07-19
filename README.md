# php-calendar

[![PHP Tests](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml/badge.svg)](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/tito10047/php-calendar)](LICENSE)

**Pure PHP calendar library. Zero dependencies. Built for server-side rendering.**

Feed it a date. Get back a typed `Day[][]` table. Build your own template.
Import an `.ics` file. Serve a JSON feed to FullCalendar. Run a full CalDAV server. All in one package.

---

## Why this library?

### Killer features

**Full CalDAV read _and_ write** — the library ships both a CalDAV *client* (fetch from Nextcloud, iCloud, Google Calendar) and a CalDAV *server* (`CalDavServer`). Connect Apple Calendar, Thunderbird or DAVx5 directly to your app: events created or edited on any of those clients are pushed to your PHP endpoint and saved by an interface you implement in four methods.

**Symfony UX Live Components** — render the calendar as a `LiveComponent`, wire up a `LiveAction` for navigation, and the grid re-renders without a page reload. No JavaScript state management. No REST API to design. Just PHP.

**Laravel Livewire** — same idea: a Livewire component holds the current month, Livewire actions handle navigation and event saves, Blade renders the grid. All server-side.

### Full feature list

- **Server-side grid rendering** — monthly, weekly, work-week, or any custom date range
- **CalDAV server** — `CalDavServer` handles OPTIONS / PROPFIND / REPORT / GET / PUT / DELETE; implement four methods to connect any storage backend ([docs](docs/caldav-server.md))
- **CalDAV client** — fetch events directly from Nextcloud, iCloud, Fastmail, Google Calendar
- **RFC 5545 iCal** — full parse/export: RRULE, EXDATE, RDATE, VALARM, ORGANIZER, ATTENDEE, RECURRENCE-ID, X-* props, CalDAV metadata
- **JSON feed** — one call produces FullCalendar / Toast UI / DHTMLX-compatible output
- **Agenda & time-slot views** — chronological list and hourly day grid, no extra packages
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

## CalDAV server — receive changes from Apple Calendar / Thunderbird / DAVx5

Implement **four methods** and the library handles the entire CalDAV protocol (PROPFIND, REPORT, PUT, DELETE, OPTIONS, GET):

```php
use Tito10047\Calendar\Server\CalendarEventStoreInterface;
use Tito10047\Calendar\Server\CalDavServer;
use Tito10047\Calendar\ICal\ICalEvent;

class DatabaseCalendarStore implements CalendarEventStoreInterface
{
    public function putEvent(string $uid, ICalEvent $event): void
    {
        // save or update in your database
    }
    public function deleteEvent(string $uid): void { /* … */ }
    public function getEvent(string $uid): ?ICalEvent { /* … */ }
    public function listEvents(DateTimeImmutable $from, DateTimeImmutable $to): array { /* … */ }
}
```

Wire up routes in your framework (Symfony / Laravel / plain PHP) and delegate to `CalDavServer`:

```php
// Symfony controller — one line per HTTP method
public function put(Request $request, string $uid): Response
{
    $r = (new CalDavServer($this->store))->handlePut($uid, $request->getContent());
    return new Response($r->body, $r->statusCode, ['Content-Type' => $r->contentType] + $r->headers);
}
```

Connect Apple Calendar: `caldav://yourapp.com/caldav/` → done. Every create / edit / delete on the client is saved by your store.

→ **[Full guide with Symfony, Laravel and plain PHP examples](docs/caldav-server.md)**

---

## Symfony UX Live Components

Render the calendar inside a `LiveComponent` for reactive month navigation without a page reload:

```php
// src/Twig/Components/CalendarComponent.php
#[AsLiveComponent]
class CalendarComponent extends AbstractController
{
    public int $year;
    public int $month;

    #[LiveProp]
    public function getCalendar(): Calendar
    {
        return Calendar::forMonth($this->year, $this->month)
            ->setDataLoader(new DatabaseCalendarStore($this->store));
    }

    #[LiveAction]
    public function nextMonth(): void { $this->month++; if ($this->month > 12) { $this->month = 1; $this->year++; } }

    #[LiveAction]
    public function prevMonth(): void { $this->month--; if ($this->month < 1) { $this->month = 12; $this->year--; } }
}
```

```twig
{# templates/components/Calendar.html.twig #}
<div {{ attributes }}>
    <button data-action="live#action" data-live-action-param="prevMonth">‹</button>
    <button data-action="live#action" data-live-action-param="nextMonth">›</button>
    {% for week in this.calendar.getDaysTable() %}
        <div class="week">
            {% for day in week %}
                <div class="day {{ day.today ? 'today' }}">{{ day.date|date('j') }}</div>
            {% endfor %}
        </div>
    {% endfor %}
</div>
```

→ **[Full Live Components + CalDAV server guide](docs/caldav-server.md)**

---

## Laravel Livewire

```php
// app/Livewire/CalendarWidget.php
class CalendarWidget extends Component
{
    public int $year;
    public int $month;

    public function mount(): void { $this->year = (int) now()->format('Y'); $this->month = (int) now()->format('n'); }
    public function nextMonth(): void { $this->month++; if ($this->month > 12) { $this->month = 1; $this->year++; } }
    public function prevMonth(): void { $this->month--; if ($this->month < 1) { $this->month = 12; $this->year--; } }

    public function render(): \Illuminate\View\View
    {
        $calendar = Calendar::forMonth($this->year, $this->month)
            ->setDataLoader(app(DatabaseCalendarStore::class));
        return view('livewire.calendar-widget', ['table' => $calendar->getDaysTable()]);
    }
}
```

→ **[Full Livewire + CalDAV server guide](docs/caldav-server.md)**

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

## Comparison with other PHP calendar libraries

| Feature | **php-calendar** | sabre/dav | sabre/vobject | eluceo/ical | spatie/icalendar-generator | spatie/calendar-links |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Server-side grid (Day[][]) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| CalDAV server (receive PUT/DELETE) | ✅ | ✅ full | ❌ | ❌ | ❌ | ❌ |
| CalDAV client (fetch from Nextcloud/iCloud) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| RFC 5545 iCal import | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| RFC 5545 iCal export | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| RRULE / recurrence expansion | ✅ | ✅ | ✅ | ✅ | partial | ❌ |
| RECURRENCE-ID overrides | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| VALARM / ORGANIZER / ATTENDEE | ✅ | ✅ | ✅ | ✅ | partial | ❌ |
| Symfony Live Components | ✅ native | ❌ | ❌ | ❌ | ❌ | ❌ |
| Laravel Livewire | ✅ native | ❌ | ❌ | ❌ | ❌ | ❌ |
| Zero dependencies | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

**When to choose what:**
- `sabre/dav` — enterprise-grade full CalDAV/WebDAV server with ACL, scheduling, and CardDAV; significantly more complex to set up
- `sabre/vobject` — best-in-class iCal/vCard parsing and manipulation if you don't need grid rendering
- `eluceo/ical` / `spatie/icalendar-generator` — iCal *export* only, clean fluent API, no import or grid
- `spatie/calendar-links` — one-click "Add to Google Calendar / iCal" links, nothing more
- **php-calendar** — the only library that combines grid rendering, full CalDAV (client + server), and native reactive component support in a single zero-dependency package

---

## Further reading

| Topic | File |
|-------|------|
| **CalDAV server — Symfony / Laravel / plain PHP / Live Components / Livewire** | [docs/caldav-server.md](docs/caldav-server.md) |
| CalDAV client (Nextcloud, iCloud, Google) | [docs/caldav.md](docs/caldav.md) |
| Disable / enable model (three layers) | [docs/disable-model.md](docs/disable-model.md) |
| Attaching custom data to days | [docs/data-loading.md](docs/data-loading.md) |
| Navigation, caching, CalendarConfig | [docs/navigation.md](docs/navigation.md) |
| Recurring events (RRULE, BYSETPOS, BYMONTHDAY, RDATE, WKST) | [docs/recurrence.md](docs/recurrence.md) |
| iCal import, export, VALARM, ORGANIZER, RECURRENCE-ID, X-* | [docs/ical.md](docs/ical.md) |
| JSON feed for FullCalendar / Toast UI | [docs/json-feed.md](docs/json-feed.md) |
| Agenda view and day / time-slot view | [docs/views.md](docs/views.md) |
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
