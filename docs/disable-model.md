# Disable / enable model

Days are resolved through three ordered layers. Higher priority wins.

| Priority | Layer | Survives `nextPeriod()` | Use for |
|----------|-------|------------------------|---------|
| 1 (highest) | `enabledDays` | no | exception dates — override a weekday rule for one specific date |
| 2 | `disabledDays` | no | date-specific disabled days — public holidays, one-off closures |
| 3 (lowest) | `disabledDayNames` | **yes** | structural weekday pattern — weekends, non-working days |

A day is enabled when: it is in `enabledDays`, **or** it is not in `disabledDays` and its weekday name is not in `disabledDayNames`.

---

## Structural weekday rule

Stored as `DayName[]`, not concrete dates. Survives period navigation automatically.

```php
$calendar = Calendar::forMonth(2024, 11)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday);

$december = $calendar->nextPeriod();
// December weekends are also disabled — the rule carries over
$december->getDisabledDayNames(); // [DayName::Saturday, DayName::Sunday]
```

---

## Date-specific disabled days

Concrete dates. Reset when moving to another period with `nextPeriod()` / `prevPeriod()`.

```php
$calendar = $calendar
    ->disableDays(new DateTimeImmutable('2024-11-11'))   // single date
    ->disableDaysRange(                                  // date range
        from: new DateTimeImmutable('2024-12-24'),
        to:   new DateTimeImmutable('2024-12-26'),
    )
    ->disableWeek(weekNum: 47);                          // full ISO week
```

---

## Exception override

`enableDays()` overrides both `disabledDays` and `disabledDayNames` for a specific date.

```php
// All Saturdays and Sundays are disabled...
$calendar = Calendar::forMonth(2024, 11)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday);

// ...except this one Saturday
$calendar = $calendar->enableDays(new DateTimeImmutable('2024-11-30'));

$calendar->isDayDisabled(new DateTimeImmutable('2024-11-30')); // false
$calendar->isDayDisabled(new DateTimeImmutable('2024-11-23')); // true (other Saturdays)
```

`disableDays()` on a date that is in `enabledDays` removes it from the exception list — explicit disable always wins.

---

## Inspection

```php
$calendar->getDisabledDayNames(); // list<DayName>          — structural rules
$calendar->getDisabledDays();     // list<DateTimeImmutable> — date-specific
$calendar->getEnabledDays();      // list<DateTimeImmutable> — exceptions
$calendar->isDayDisabled($day);   // bool — evaluates all three layers
```

---

## What `withDate()` does

`withDate()` is a direct date change with no navigation semantics — all three layers are preserved.

```php
$march = $calendar->withDate(new DateTimeImmutable('2025-03-01'));
// disabledDayNames, disabledDays, and enabledDays all carry over
```

Use `nextPeriod()` / `prevPeriod()` when you want structural rules to persist but date-specific ones to reset.
