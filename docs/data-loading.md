# Attaching data to days

Implement `DayDataLoaderInterface` to attach arbitrary data to each calendar day — events, booking counts, availability flags, anything.

```php
interface DayDataLoaderInterface
{
    public function load(DateTimeImmutable $from, DateTimeImmutable $to): void;
    public function getData(DateTimeImmutable $date): array;
}
```

`load()` is called once with the full grid range — bulk-fetch here.
`getData()` is called once per day — return the data for that date.

---

## Example: event loader

```php
class EventLoader implements DayDataLoaderInterface
{
    private array $byDate = [];

    public function load(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $rows = $this->db->query(
            'SELECT * FROM events WHERE date BETWEEN ? AND ?',
            [$from->format('Y-m-d'), $to->format('Y-m-d')]
        );
        foreach ($rows as $row) {
            $this->byDate[$row['date']][] = $row;
        }
    }

    public function getData(DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }
}
```

Attach it to the calendar:

```php
$calendar = Calendar::forMonth(2024, 11)
    ->setDataLoader(new EventLoader($db));
```

Each `Day` in the table will now have `$day->data` populated with whatever your loader returned (or `null` if no loader is attached).

---

## ArrayDataLoader

When you already have the data in memory (e.g. from a cache), use `ArrayDataLoader` instead of writing a loader:

```php
use Tito10047\Calendar\DataLoader\ArrayDataLoader;

$data = [
    '2024-11-05' => [['title' => 'Team meeting']],
    '2024-11-12' => [['title' => 'Sprint review']],
];

$calendar = Calendar::forMonth(2024, 11)
    ->setDataLoader(new ArrayDataLoader($data));
```

This is the bridge used by `Calendar::fromConfig()` when you pass pre-loaded data. See [docs/navigation.md](navigation.md) for the caching flow.

---

## Template access

```twig
{% for event in day.data ?? [] %}
    <div class="event">{{ event.title }}</div>
{% endfor %}
```

```blade
@foreach ($day->data ?? [] as $event)
    <div class="event">{{ $event['title'] }}</div>
@endforeach
```

The data shape is entirely yours — no schema enforced by the library.
