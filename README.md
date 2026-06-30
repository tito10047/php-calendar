# php-calendar

[![PHP Tests](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml/badge.svg)](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/tito10047/php-calendar)](LICENSE)

**Pure PHP calendar library. Zero dependencies. Built for server-side rendering.**

Feed it a date. Get back a typed `Day[][]` table. Build your own template.

---

## Why server-side?

- Works with Symfony UX Turbo / Livewire — no hydration, no client state sync
- Zero frontend dependencies — just your own HTML and CSS
- Every cell carries arbitrary data attached via a simple interface
- Fully immutable — every mutation returns a new instance

---

## Installation

```bash
composer require tito10047/php-calendar
```

Requires PHP 8.2+. No other dependencies.

---

## Quick start

```php
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Enum\CalendarType;
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
                                <div class="event">{{ event.title }}</div>
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

## The `Day` object

Every cell is a readonly value object:

```php
$day->date      // DateTimeImmutable
$day->ghost     // belongs to adjacent month (grid padding, Monthly only)
$day->today     // matches today's date
$day->enabled   // not disabled by any rule
$day->data      // ?array — whatever your DayDataLoaderInterface returned
```

---

## Calendar types

```php
Calendar::forMonth(2024, 11)                         // full month, aligned to complete weeks
Calendar::forWeek(new DateTimeImmutable('2024-11-04')) // one week, Mon–Sun
Calendar::forToday(CalendarType::WorkWeek)           // this week, Mon–Fri
```

Or with navigation:

```php
$next = $calendar->nextPeriod(); // next month / next week — depends on type
$prev = $calendar->prevPeriod();
```

---

## Disable and enable days

Three independent layers — see [docs/disable-model.md](docs/disable-model.md) for the full model.

```php
$calendar = Calendar::forMonth(2024, 11)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday)  // structural — survives navigation
    ->disableDays(new DateTimeImmutable('2024-11-11'))        // date-specific — resets on navigation
    ->enableDays(new DateTimeImmutable('2024-11-30'));        // exception — override for one date
```

---

## Further reading

| Topic | File |
|-------|------|
| Disable / enable model (three layers) | [docs/disable-model.md](docs/disable-model.md) |
| Attaching custom data to days | [docs/data-loading.md](docs/data-loading.md) |
| Navigation, caching, CalendarConfig | [docs/navigation.md](docs/navigation.md) |
| Recurring events (RFC 5545 RRULE) | [docs/recurrence.md](docs/recurrence.md) |
| iCal import and export | [docs/ical.md](docs/ical.md) |
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
