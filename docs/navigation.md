# Navigation and caching

## Period navigation

`nextPeriod()` and `prevPeriod()` advance or retreat by one period. The period length depends on the generator:

| Generator | Step |
|-----------|------|
| `CalendarType::Monthly` | 1 month |
| `CalendarType::Weekly` | 1 week |
| `CalendarType::WorkWeek` | 1 week |
| `fromDateRange()` / `DateRangeGenerator` | the window's own length (e.g. a 10-day window moves by 10 days) |
| Custom `DaysGeneratorInterface` | whatever `getNavigationStep()` returns |

Month grids navigate from the 1st of the month, so a reference date on the 29th–31st never skips a
month (`Calendar::forMonth(2025, 1)->withDate(new DateTimeImmutable('2025-01-31'))->nextPeriod()` is February).

```php
$november = Calendar::forMonth(2024, 11);
$december = $november->nextPeriod();
$october  = $november->prevPeriod();

$week1 = Calendar::forWeek(new DateTimeImmutable('2024-11-04'));
$week2 = $week1->nextPeriod(); // Mon 11 – Sun 17 Nov
```

**What resets:** `disabledDays` and `enabledDays` (date-specific, belong to the old period).
**What persists:** `disabledDayNames`, `dataLoader`, `startDay`, `daysGenerator`.

---

## Arbitrary date jump

`withDate()` changes the reference date while preserving everything else — including date-specific disabled days. Use it when you want full control without navigation semantics.

```php
$march = $calendar->withDate(new DateTimeImmutable('2025-03-01'));
```

For a `fromDateRange()` calendar, `withDate()` moves the whole window so it starts at the new date (same length).

---

## Grid boundaries

```php
$range = $calendar->getDateRange();
// ['from' => DateTimeImmutable, 'to' => DateTimeImmutable]
```

The range covers the actual grid — for `Monthly` this includes ghost-day padding. Useful for building a single bulk query before calling `getDaysTable()`.

To test whether a day is at the edge of the displayed period, use `isFirstDayOfPeriod()` / `isLastDayOfPeriod()` — the 1st / last day of the month for `Monthly` grids (ghost padding excluded), otherwise the first / last day of the grid. `isFirstDay()` / `isLastDay()` keep month semantics for every calendar type.

```php
$calendar->isFirstDayOfPeriod($day); // bool — accepts Day or DateTimeInterface
$calendar->isLastDayOfPeriod($day);
```

---

## CalendarConfig — serialisable configuration

`CalendarConfig` extracts all configuration into a pure value object with no runtime dependencies. It is fully serialisable — safe to store in Redis, Memcached, or any cache backend.

```php
use Tito10047\Calendar\CalendarConfig;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;
use Tito10047\Calendar\Enum\WeekStart;

$config = new CalendarConfig(
    date:             new DateTimeImmutable('2024-11-01'),
    type:             CalendarType::Monthly,
    startDay:         WeekStart::Monday,
    disabledDayNames: [DayName::Saturday, DayName::Sunday],
    disabledDays:     [new DateTimeImmutable('2024-11-11')],
    enabledDays:      [new DateTimeImmutable('2024-11-30')],
);

echo $config->cacheKey();
// calendar:2024-11-01:<timezone>:Monthly:Monday:<hash>:<hash>:<hash>
```

The key is deterministic and order-independent — the order of items in the disable/enable arrays does not affect the key. It includes the timezone of `date`, so the same date in two timezones yields two keys.

`disabledDayNames` must contain only `DayName` values (anything else throws `InvalidArgumentException`); duplicates are removed.

`type` accepts any `DaysGeneratorInterface`, not only `CalendarType` — e.g. a fixed-length window:

```php
use Tito10047\Calendar\DataLoader\DateRangeGenerator;

$config = new CalendarConfig(
    date: new DateTimeImmutable('2024-11-04'),
    type: new DateRangeGenerator(new DateTimeImmutable('2024-11-04'), new DateTimeImmutable('2024-11-17')),
);
```

Custom generators must be serialisable for `cacheKey()` to be stable.

---

## Cacheable flow

```php
// Build configuration — no DB, no loader, pure value object
$config = new CalendarConfig(
    date: new DateTimeImmutable('2024-11'),
    type: CalendarType::Monthly,
    disabledDayNames: [DayName::Saturday, DayName::Sunday],
);

// Load data — separate concern, cache independently
$data = $cache->get($config->cacheKey(), function () use ($config, $loader) {
    ['from' => $from, 'to' => $to] = Calendar::fromConfig($config)->getDateRange();

    // Your own DayDataLoaderInterface, flattened into a plain Y-m-d => array map
    $loaded = $loader->load($from, $to);
    $data   = [];
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        $data[$d->format('Y-m-d')] = $loaded->getData($d);
    }
    return $data;
});

// Reconstruct calendar — no DB involved
$calendar = Calendar::fromConfig($config, $data);
```

`Calendar::fromConfig()` accepts a second `$data` argument (a `Y-m-d → array` map) and wires it into an `ArrayDataLoader` automatically.

---

## Symfony controller example

```php
#[Route('/calendar/{year}/{month}', name: 'calendar')]
public function index(int $year, int $month): Response
{
    $config = new CalendarConfig(
        date:             new DateTimeImmutable("$year-$month-01"),
        type:             CalendarType::Monthly,
        disabledDayNames: [DayName::Saturday, DayName::Sunday],
    );

    $data     = $this->cache->get($config->cacheKey(), fn () => $this->loadEvents($config));
    $calendar = Calendar::fromConfig($config, $data);

    return $this->render('calendar/index.html.twig', [
        'table'    => $calendar->getDaysTable(),
        'calendar' => $calendar,
        'prev'     => $calendar->prevPeriod()->getDate(),
        'next'     => $calendar->nextPeriod()->getDate(),
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
