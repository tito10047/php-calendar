# Resource calendar

A resource calendar shows resources (rooms, people, vehicles) as rows and days as columns.

```
              | Mon 4.11 | Tue 5.11 | Wed 6.11 | ...
──────────────────────────────────────────────────────
Meeting room  | [Meeting]|          | [Review] |
Jozef Môstka  | [Meeting]| [Call]   |          |
VW Golf       |          |          | [Trip]   |
```

Use cases: hotel rooms, car rental, meeting room booking, staff scheduling.

---

## Setup

### 1. Define your resources

```php
use Tito10047\Calendar\Resource\ResourceInterface;

class Room implements ResourceInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $name,
    ) {}

    public function getResourceId(): string   { return $this->id; }
    public function getResourceName(): string { return $this->name; }
}
```

### 2. Implement the loader

```php
use Tito10047\Calendar\Resource\ResourceDataLoaderInterface;
use Tito10047\Calendar\Resource\ResourceInterface;

class RoomBookingLoader implements ResourceDataLoaderInterface
{
    private array $byResource = [];

    public function load(ResourceInterface $resource, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $bookings = $this->db->query(
            'SELECT * FROM bookings WHERE room_id = ? AND date BETWEEN ? AND ?',
            [$resource->getResourceId(), $from->format('Y-m-d'), $to->format('Y-m-d')]
        );
        foreach ($bookings as $booking) {
            $this->byResource[$resource->getResourceId()][$booking['date']][] = $booking;
        }
    }

    public function getData(ResourceInterface $resource, DateTimeImmutable $date): array
    {
        return $this->byResource[$resource->getResourceId()][$date->format('Y-m-d')] ?? [];
    }
}
```

### 3. Build the resource calendar

```php
use Tito10047\Calendar\Resource\ResourceCalendar;

$baseCalendar = Calendar::forMonth(2024, 11)
    ->disableDaysByName(DayName::Saturday, DayName::Sunday);

$rooms = [
    new Room('a', 'Meeting room A'),
    new Room('b', 'Meeting room B'),
];

$rc = new ResourceCalendar($baseCalendar, $rooms, new RoomBookingLoader($db));
```

---

## Rendering

```php
$table = $rc->getResourceTable();
// array<resourceId, Day[][]> — same Day[][] shape as Calendar::getDaysTable()
```

```twig
<table class="resource-calendar">
    <thead>
        <tr>
            <th>Resource</th>
            {% for day in firstWeekDays %}
                <th>{{ day.date|date('D j.n.') }}</th>
            {% endfor %}
        </tr>
    </thead>
    <tbody>
        {% for resource in resources %}
            <tr>
                <td class="resource-name">{{ resource.resourceName }}</td>
                {% for weekNum, week in table[resource.resourceId] %}
                    {% for day in week %}
                        <td class="{{ day.enabled ? '' : 'unavailable' }}">
                            {% for booking in day.data ?? [] %}
                                <div class="booking">{{ booking.title }}</div>
                            {% endfor %}
                        </td>
                    {% endfor %}
                {% endfor %}
            </tr>
        {% endfor %}
    </tbody>
</table>
```

---

## How it works

`ResourceCalendar` calls `Calendar::setDataLoader()` with a `ResourceLoaderAdapter` for each resource.
The base calendar's `disabledDayNames`, `disabledDays`, `startDay`, and `daysGenerator` apply uniformly
to every resource row — shared time axis, resource-specific data.

Results are lazy-computed and cached per resource. Calling `getDaysTableForResource()` twice for the same resource does not trigger a second `load()`.
