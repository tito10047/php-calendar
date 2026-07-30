# Navigation and caching

## Period navigation

`nextPeriod()` and `prevPeriod()` advance or retreat by one period. The period length depends on the generator:

| Generator | Step |
|-----------|------|
| `CalendarType::Monthly` | 1 month |
| `CalendarType::Weekly` | 1 week |
| `CalendarType::WorkWeek` | 1 week |
| Custom `DaysGeneratorInterface` | whatever `getNavigationStep()` returns |

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

---

## Grid boundaries

```php
$range = $calendar->getDateRange();
// ['from' => DateTimeImmutable, 'to' => DateTimeImmutable]
```

The range covers the actual grid — for `Monthly` this includes ghost-day padding. Useful for building a single bulk query before calling `getDaysTable()`.

---

## CalendarConfig — serialisable configuration

`CalendarConfig` extracts all configuration into a pure value object with no runtime dependencies. It is fully serialisable — safe to store in Redis, Memcached, or any cache backend.

```php
use Tito10047\Calendar\CalendarConfig;
use Tito10047\Calendar\Enum\CalendarType;
use Tito10047\Calendar\Enum\DayName;

$config = new CalendarConfig(
    date:             new DateTimeImmutable('2024-11-01'),
    type:             CalendarType::Monthly,
    startDay:         DayName::Monday,
    disabledDayNames: [DayName::Saturday, DayName::Sunday],
    disabledDays:     [new DateTimeImmutable('2024-11-11')],
    enabledDays:      [new DateTimeImmutable('2024-11-30')],
);

echo $config->cacheKey();
// calendar:2024-11-01:Monthly:Monday:<hash>:<hash>:<hash>
```

The key is deterministic and order-independent — the order of items in the disable/enable arrays does not affect the key.

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
    return $loader->computeData($from, $to); // returns array<Y-m-d, array>
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
