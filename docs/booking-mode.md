# Booking mode pattern

This is not a built-in feature — it's a pattern that emerges naturally from the library's building blocks.
The idea: use `Day::$enabled` and `Day::$data` together to represent availability and occupancy in one pass.

---

## The pattern

1. **Structural rules** disable non-bookable weekdays permanently.
2. **Data loader** marks individual days as unavailable based on real data (full bookings, maintenance, etc.).
3. **Template** renders each cell differently based on `enabled` and `data`.

```php
$calendar = Calendar::forMonth(2024, 11)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday)    // permanently closed
    ->setDataLoader(new AvailabilityLoader($db, $resourceId));

$table = $calendar->getDaysTable();
```

---

## Availability loader

The loader has two responsibilities: populate data (bookings) and signal unavailability by returning a special marker. The calendar handles the `enabled` flag separately via `disableDays()` — but for dynamic availability (e.g. "fully booked"), it's cleaner to express it through data and let the template decide.

```php
class AvailabilityLoader implements DayDataLoaderInterface
{
    private array $byDate = [];

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $rows = $this->db->query(
            'SELECT date, COUNT(*) as bookings, capacity FROM slots
             WHERE resource_id = ? AND date BETWEEN ? AND ?
             GROUP BY date',
            [$this->resourceId, $from->format('Y-m-d'), $to->format('Y-m-d')]
        );

        foreach ($rows as $row) {
            $this->byDate[$row['date']] = [
                'bookings'  => (int) $row['bookings'],
                'capacity'  => (int) $row['capacity'],
                'available' => $row['bookings'] < $row['capacity'],
            ];
        }
    }

    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? ['bookings' => 0, 'capacity' => null, 'available' => true];
    }
}
```

---

## Template

```twig
{% for week in table %}
    <tr>
        {% for day in week %}
            {% set avail = day.data %}
            <td class="
                {{ day.ghost    ? 'ghost'       : '' }}
                {{ day.today    ? 'today'        : '' }}
                {{ not day.enabled ? 'closed'   : '' }}
                {{ day.enabled and avail and not avail.available ? 'full' : '' }}
                {{ day.enabled and avail and avail.available ? 'open' : '' }}
            ">
                {% if not day.ghost and day.enabled %}
                    <span class="date">{{ day.date|date('j') }}</span>
                    {% if avail %}
                        <span class="slots">
                            {{ avail.available
                                ? (avail.capacity - avail.bookings) ~ ' free'
                                : 'Full' }}
                        </span>
                    {% endif %}
                {% elseif not day.ghost %}
                    <span class="date">{{ day.date|date('j') }}</span>
                    <span class="closed-label">Closed</span>
                {% endif %}
            </td>
        {% endfor %}
    </tr>
{% endfor %}
```

---

## Caching the whole thing

Availability data changes frequently, but the calendar structure (which days exist, which are structurally closed) does not. Split the cache accordingly:

```php
$config = new CalendarConfig(
    date:             new DateTimeImmutable("$year-$month-01"),
    type:             CalendarType::Monthly,
    disabledDayNames: [DayName::Saturday, DayName::Sunday],
);

// Short TTL — availability changes throughout the day
$data = $cache->get("availability:{$config->cacheKey()}:{$resourceId}", function () use ($config, $resourceId) {
    $cal    = Calendar::fromConfig($config);
    $range  = $cal->getDateRange();
    return (new AvailabilityLoader($this->db, $resourceId))->computeAll($range['from'], $range['to']);
}, ttl: 60);

$calendar = Calendar::fromConfig($config, $data);
```

---

## Resource calendar variant

For multi-resource booking (rooms, people, equipment), use `ResourceCalendar` — each row is one resource with its own availability data. See [docs/resource-calendar.md](resource-calendar.md).
