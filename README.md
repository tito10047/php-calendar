# php-calendar

[![PHP Tests](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml/badge.svg)](https://github.com/tito10047/php-calendar/actions/workflows/symfony.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue)](https://www.php.net/)
[![License](https://img.shields.io/github/license/tito10047/php-calendar)](LICENSE)

**A pure PHP server-side calendar library built for Symfony UX and Laravel Livewire.**

Stop fighting JavaScript calendar widgets that break your SSR, bloat your bundle, and fight with your server state. This library renders calendars entirely on the server — immutable, composable, and framework-friendly. Feed it your events, disabled days, or any custom data. Get back clean HTML. Done.

---

## Why server-side?

- **Works with Symfony UX Turbo / Livewire out of the box** — no hydration, no client state sync
- **Zero frontend dependencies** — just HTML + your own CSS
- **Full control over every cell** — attach any data to any day via a typed interface
- **Truly immutable** — every mutation (next month, disabled days) returns a new instance
- **Fully replaceable renderer chain** — swap any layer without touching the rest

---

## Installation

```bash
composer require tito10047/php-calendar
```

---

## Quick start

```php
use Tito10047\Calendar\Calendar;
use Tito10047\Calendar\Renderer;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

$calendar = new Calendar(
    date: new DateTimeImmutable('2024-11-01'),
    daysGenerator: CalendarType::Monthly,
    startDay: DayName::Monday,
);

$renderer = Renderer::factory(CalendarType::Monthly, 'calendar');

echo $renderer->render($calendar);
```

That's it. You get a fully structured `<table class="calendar">` with ghost days, today marker, and day headers.

---

## Calendar types

Three built-in views, zero configuration:

```php
CalendarType::Monthly   // full month, aligned to complete weeks
CalendarType::Weekly    // one week, Mon–Sun
CalendarType::WorkWeek  // one week, Mon–Fri
```

---

## Disabling days

All methods are immutable — they return a new `Calendar` instance.

```php
// Disable specific dates
$calendar = $calendar->disableDays(
    new DateTimeImmutable('2024-11-11'),
    new DateTimeImmutable('2024-11-15'),
);

// Disable all weekends
$calendar = $calendar
    ->disableDaysByName(DayName::Saturday, DayName::Sunday);

// Disable a date range
$calendar = $calendar->disableDaysRange(
    from: new DateTimeImmutable('2024-11-25'),
    to:   new DateTimeImmutable('2024-11-30'),
);

// Disable an entire ISO week
$calendar = $calendar->disableWeek(weekNum: 47);
```

---

## Navigating months

```php
$november = new Calendar(new DateTimeImmutable('2024-11-01'), CalendarType::Monthly);
$december = $november->nextMonth();
$october  = $november->prevMonth();
```

> **Note:** `nextMonth()` and `prevMonth()` reset disabled days. Re-apply them on the new instance if needed.

---

## Attaching custom data to days

The real power: attach anything to any day — events, holidays, booking counts, whatever.

Implement `DayDataLoaderInterface`:

```php
use Tito10047\Calendar\Interface\DayDataLoaderInterface;

class EventLoader implements DayDataLoaderInterface
{
    private array $byDate = [];

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        // Called once with the full date range — bulk-load here
        $events = $this->db->query(
            'SELECT * FROM events WHERE date BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        foreach ($events as $event) {
            $this->byDate[$event['date']][] = $event;
        }
    }

    public function getData(DateTimeImmutable $date): array
    {
        // Called per day — return data for this specific date
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
```

Attach it to your calendar:

```php
$calendar = $calendar->setDataLoader(new EventLoader());
```

Each `Day` object will now have `$day->data` populated with whatever your loader returned.

---

## Working with the days table directly

Skip the renderer entirely and build your own template:

```php
$table = $calendar->getDaysTable();
// array<int weekNumber, array<int dayNumber 1–7, Day>>
```

### In Twig (Symfony)

```twig
<table class="calendar">
    <thead>
        <tr>
            <th>Mon</th><th>Tue</th><th>Wed</th>
            <th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th>
        </tr>
    </thead>
    <tbody>
        {% for week in table %}
            <tr>
                {% for day in week %}
                    <td class="
                        {{ day.ghost    ? 'ghost'    : '' }}
                        {{ day.today    ? 'today'    : '' }}
                        {{ day.enabled  ? ''         : 'disabled' }}
                    ">
                        {% if not day.ghost %}
                            <span class="date">{{ day.date|date('j') }}</span>

                            {% if day.data %}
                                <ul class="events">
                                    {% for event in day.data %}
                                        <li>{{ event.title }}</li>
                                    {% endfor %}
                                </ul>
                            {% endif %}
                        {% endif %}
                    </td>
                {% endfor %}
            </tr>
        {% endfor %}
    </tbody>
</table>
```

### In a Blade template (Laravel)

```blade
<table class="calendar">
    <tbody>
        @foreach ($table as $week)
            <tr>
                @foreach ($week as $day)
                    <td @class([
                        'ghost'    => $day->ghost,
                        'today'    => $day->today,
                        'disabled' => !$day->enabled,
                    ])>
                        @unless ($day->ghost)
                            <span class="date">{{ $day->date->format('j') }}</span>

                            @foreach ($day->data ?? [] as $event)
                                <div class="event">{{ $event['title'] }}</div>
                            @endforeach
                        @endunless
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
```

---

## Symfony UX / Turbo example

The calendar is immutable — perfect for Turbo Frames or Live Components where PHP re-renders on every interaction.

```php
// src/Controller/CalendarController.php
#[Route('/calendar/{year}/{month}', name: 'calendar')]
public function index(int $year, int $month): Response
{
    $calendar = new Calendar(
        date: new DateTimeImmutable("$year-$month-01"),
        daysGenerator: CalendarType::Monthly,
        startDay: DayName::Monday,
    );

    $calendar = $calendar
        ->disableDaysByName(DayName::Sunday)
        ->setDataLoader(new EventLoader($this->db));

    return $this->render('calendar/index.html.twig', [
        'table'    => $calendar->getDaysTable(),
        'calendar' => $calendar,
        'prev'     => $calendar->prevMonth()->getDate(),
        'next'     => $calendar->nextMonth()->getDate(),
    ]);
}
```

```twig
{# templates/calendar/index.html.twig #}
<turbo-frame id="calendar">
    <nav>
        <a href="{{ path('calendar', {year: prev|date('Y'), month: prev|date('n')}) }}">← Prev</a>
        <strong>{{ calendar.date|date('F Y') }}</strong>
        <a href="{{ path('calendar', {year: next|date('Y'), month: next|date('n')}) }}">Next →</a>
    </nav>

    {# ... render table ... #}
</turbo-frame>
```

No JavaScript. No state sync. Every click is a Turbo Frame navigation that re-renders server-side.

---

## Using the built-in HTML renderer

When you just need clean HTML without writing a template:

```php
$renderer = Renderer::factory(CalendarType::Monthly, translationDomain: 'calendar');
echo $renderer->render($calendar);
```

Output structure:

```html
<table class="calendar">
    <thead>
        <tr>
            <td><span class="day-name">Mon</span></td>
            <!-- ... -->
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="ghost"><!-- prev month --></td>
            <td class="today"><div class="day"><span class="name">1</span></div></td>
            <td class="disabled"><div class="day"><span class="name">2</span></div></td>
            <!-- ... -->
        </tr>
    </tbody>
</table>
```

**Available CSS classes on `<td>`:**

| Class      | Meaning                            |
|------------|------------------------------------|
| `ghost`    | Day belongs to an adjacent month   |
| `today`    | Matches today's date               |
| `disabled` | Disabled via any `disable*` method |

---

## The `Day` object

Every cell in the table is a `Day` value object:

```php
final readonly class Day
{
    public DateTimeImmutable $date;
    public bool $ghost;     // belongs to adjacent month (grid filler)
    public bool $today;     // matches current system date
    public bool $enabled;   // not in the disabled list
    public ?array $data;    // populated by DayDataLoaderInterface
}
```

---

## Custom days generator

Need a custom date range — a fortnight, a quarter, a fiscal week? Implement `DaysGeneratorInterface`:

```php
use Tito10047\Calendar\Interface\DaysGeneratorInterface;
use Tito10047\Calendar\Enum\DayName;

class FortnightGenerator implements DaysGeneratorInterface
{
    public function getDays(DateTimeImmutable $day, DayName $firstDay): array
    {
        $start = $day->modify('monday this week');
        $days  = [];

        for ($i = 0; $i < 14; $i++) {
            $days[] = $start->modify("+$i days");
        }

        return $days;
    }
}

$calendar = new Calendar(
    date: new DateTimeImmutable(),
    daysGenerator: new FortnightGenerator(),
);
```

---

## Custom events

Implement `EventInterface` for structured events with time ranges:

```php
use Tito10047\Calendar\Interface\EventInterface;

class Meeting implements EventInterface
{
    public function __construct(
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
        private string $title,
    ) {}

    public function getFrom(): DateTimeImmutable  { return $this->from; }
    public function getTo(): DateTimeImmutable    { return $this->to; }
    public function getTitle(): string            { return $this->title; }
    public function getDescription(): string      { return ''; }
    public function getStatus(): string           { return 'confirmed'; }
}
```

Pair with a custom `EventRendererInterface` to control how events appear in each day cell.

---

## Renderer chain

The built-in renderer is fully composable. Swap any layer:

```
Renderer
└── MonthRendererInterface          ← wraps everything in <table>
    ├── DayNameRendererInterface    ← renders column headers
    └── WeekRowRendererInterface    ← renders each <tr>
         └── DayRendererInterface  ← renders each <td> content
              └── EventRendererInterface  ← renders events within a day
```

Replace any single piece without touching the others:

```php
use Tito10047\Calendar\Renderer;
use Tito10047\Calendar\Renderer\MonthRenderer;
use Tito10047\Calendar\Renderer\DayNameRenderer;
use Tito10047\Calendar\Renderer\WeekRowRenderer;
use Tito10047\Calendar\Renderer\EventRenderer;

$eventRenderer   = new EventRenderer($translator, 'calendar');
$dayRenderer     = new MyCustomDayRenderer($eventRenderer);   // ← your implementation
$weekRowRenderer = new WeekRowRenderer($dayRenderer);
$dayNameRenderer = new DayNameRenderer($translator, 'calendar');
$monthRenderer   = new MonthRenderer($dayNameRenderer, $weekRowRenderer);

$renderer = new Renderer($monthRenderer);
```

Or pass your `MonthRendererInterface` directly to bypass everything:

```php
$renderer = new Renderer(new MyFullyCustomRenderer());
```

---

## Internationalization

The library uses `symfony/contracts` `TranslatorInterface`. By default it's a no-op (returns keys as-is).

Plug in a real Symfony translator by building the renderer chain manually:

```php
// $translator is your Symfony TranslatorInterface implementation
$eventRenderer   = new EventRenderer($translator, 'calendar');
$dayRenderer     = new DayRenderer($eventRenderer, CalendarType::Monthly, $translator, 'calendar');
$weekRowRenderer = new WeekRowRenderer($dayRenderer);
$dayNameRenderer = new DayNameRenderer($translator, 'calendar');
$monthRenderer   = new MonthRenderer($dayNameRenderer, $weekRowRenderer);

$renderer = new Renderer($monthRenderer);
```

Translation keys used: day short names (`Mon`, `Tue`, `Wed`, `Thu`, `Fri`, `Sat`, `Sun`) and event titles.

---

## API reference

### `Calendar`

| Method | Returns | Description |
|--------|---------|-------------|
| `new Calendar($date, $generator, $startDay)` | `self` | Create a calendar for the given date |
| `disableDays(DateTimeImmutable ...$days)` | `self` | Disable specific dates |
| `disableDaysByName(DayName ...$names)` | `self` | Disable all occurrences of given weekdays |
| `disableDaysRange(?$from, ?$to)` | `self` | Disable a date range (defaults to full calendar) |
| `disableWeek(int $weekNum)` | `self` | Disable all days in an ISO week number |
| `setDataLoader($loader)` | `self` | Attach a data loader to populate `Day->data` |
| `nextMonth()` | `self` | Calendar for the next month |
| `prevMonth()` | `self` | Calendar for the previous month |
| `getDaysTable()` | `Day[][]` | 2D array keyed `[weekNumber][dayNumber 1–7]` |
| `getDate()` | `DateTimeImmutable` | The reference date |
| `getStartDay()` | `DayName` | Configured week start day |
| `isDayDisabled($day)` | `bool` | Check if a day is disabled |
| `isFirstDay($day)` | `bool` | Check if a day is the 1st of the month |
| `isLastDay($day)` | `bool` | Check if a day is the last of the month |

### `DayName`

| Case | Value |
|------|-------|
| `Monday` | 1 |
| `Tuesday` | 2 |
| `Wednesday` | 3 |
| `Thursday` | 4 |
| `Friday` | 5 |
| `Saturday` | 6 |
| `Sunday` | 7 |

---

## Running tests

```bash
composer install
vendor/bin/phpunit
```

CI runs the full suite across **PHP 8.1 – 8.5** on every push.

---

## License

MIT
