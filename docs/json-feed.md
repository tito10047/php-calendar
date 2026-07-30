# JSON feed for FullCalendar / Toast UI / DHTMLX

`JsonSerializer` converts a list of `ICalEvent` objects into a JSON array compatible with popular
JavaScript calendar libraries. Recurring events are expanded, RECURRENCE-ID overrides are applied,
and the output follows the FullCalendar event object schema.

---

## Basic usage

```php
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\Serializer\JsonSerializer;

$events = (new ICalParser())->parseUrl('https://example.com/calendar.ics');

$json = JsonSerializer::fromEvents($events)
    ->forRange(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-12-31'))
    ->toJson();
```

### As a Symfony / Laravel controller endpoint

```php
#[Route('/api/events', name: 'api_events')]
public function events(Request $request): JsonResponse
{
    $from   = new DateTimeImmutable($request->query->get('start', 'today'));
    $to     = new DateTimeImmutable($request->query->get('end', '+3 months'));
    $events = (new ICalParser())->parseFile('/path/to/calendar.ics');

    return new JsonResponse(
        JsonSerializer::fromEvents($events)->forRange($from, $to)->toArray(),
    );
}
```

### FullCalendar integration

```html
<div id="calendar"></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: 'dayGridMonth',
        events: '/api/events',  // FullCalendar sends ?start=…&end=… automatically
    });
    calendar.render();
});
</script>
```

---

## Output schema

Each event is serialised as:

```json
{
    "id":    "event-uid@source",
    "title": "Team meeting",
    "start": "2025-06-01T09:00:00",
    "end":   "2025-06-01T10:00:00",
    "allDay": false,
    "color": "#e74c3c",
    "extendedProps": {
        "description": "Q4 planning session",
        "location":    "Conference room A",
        "categories":  ["Work", "Planning"],
        "status":      "CONFIRMED",
        "url":         "https://meet.example.com/abc"
    }
}
```

| Field | Value |
|-------|-------|
| `id` | `ICalEvent::$uid` |
| `title` | `ICalEvent::$summary` |
| `start` | ISO 8601 date (`Y-m-d`) for all-day events, datetime (`Y-m-d\TH:i:s`) for timed |
| `end` | Same format, or `null` if no end time |
| `allDay` | `true` when `DTSTART` has no time component (midnight `00:00:00`) |
| `color` | `COLOR` property or `null` |
| `extendedProps` | description, location, categories, status, url |

---

## Recurring events and RECURRENCE-ID

Recurring events are automatically expanded to individual occurrences within `forRange()`.
If the event has RECURRENCE-ID overrides (a modified occurrence), the overridden occurrence
is replaced with the modified version — the override's `title`, `start`, `end` and all other
fields reflect the change:

```php
// Master: weekly every Monday
// Override: Jan 13 occurrence moved to Jan 14 at 14:00 with different title

$arr = JsonSerializer::fromEvents($events)
    ->forRange(new DateTimeImmutable('2025-01-06'), new DateTimeImmutable('2025-01-20'))
    ->toArray();

// Returns Jan 6 (normal), Jan 13 (override → title changed, start = T14:00:00), Jan 20 (normal)
```

---

## Methods

```php
// Static factory — accepts list<ICalEvent>
$serializer = JsonSerializer::fromEvents($events);

// Set the date range for expansion of recurring events
$serializer = $serializer->forRange($from, $to);

// Return as PHP array (list of associative arrays)
$array = $serializer->toArray();

// Return as JSON string
$json = $serializer->toJson();                            // default flags
$json = $serializer->toJson(JSON_PRETTY_PRINT);           // pretty-printed
$json = $serializer->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // default
```

Without `forRange()`, each event is serialised once using its `dtStart` directly (no recurrence expansion).

---

## Toast UI Calendar

Toast UI expects a very similar schema. Map `extendedProps` into `body` and `location` as needed:

```javascript
const tui = new tui.Calendar('#calendar', {
    defaultView: 'month',
});

fetch('/api/events?start=2025-01-01&end=2025-12-31')
    .then(r => r.json())
    .then(events => {
        tui.createEvents(events.map(e => ({
            id:       e.id,
            title:    e.title,
            start:    e.start,
            end:      e.end,
            isAllday: e.allDay,
            color:    e.color,
            body:     e.extendedProps.description,
            location: e.extendedProps.location,
        })));
    });
```

---

## DHTMLX Scheduler

DHTMLX uses `text`, `start_date`, `end_date`:

```javascript
scheduler.load('/api/events', 'json'); // DHTMLX can consume arrays via custom parser
// Or map manually:
const dhtmlxEvents = events.map(e => ({
    id:         e.id,
    text:       e.title,
    start_date: e.start.replace('T', ' '),
    end_date:   e.end?.replace('T', ' '),
}));
scheduler.parse(dhtmlxEvents);
```
