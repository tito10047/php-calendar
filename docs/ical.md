# iCal import and export

The library includes a minimal RFC 5545 iCal parser and a fluent exporter.
Both are zero-dependency and integrate directly with the calendar via `DayDataLoaderInterface`.

---

## Import

### From a URL, file, or string

```php
use Tito10047\Calendar\ICal\ICalParser;
use Tito10047\Calendar\ICal\ICalDataLoader;

$parser = new ICalParser();

$events = $parser->parseUrl('https://calendar.google.com/calendar/ical/.../basic.ics');
$events = $parser->parseFile('/path/to/calendar.ics');
$events = $parser->parseString($icsContent);
// → ICalEvent[]
```

### Attach to a calendar

```php
$loader   = ICalDataLoader::fromEvents($events);
$calendar = Calendar::forMonth(2024, 11)->setDataLoader($loader);

// Each Day now has $day->data populated with events for that date
```

`ICalDataLoader` calls `ICalEvent::occurrences($from, $to)` per event — recurring events are expanded lazily, only for the requested range.

### What the parser handles

- `VEVENT` — one-time events
- `RRULE` — recurring events (delegated to `RecurrenceRule`, see [docs/recurrence.md](recurrence.md))
- `EXDATE` — excluded occurrences
- `DTSTART`, `DTEND`, `DURATION`
- Timezones: `VTIMEZONE` blocks, `TZID` params on `DTSTART`/`DTEND`, UTC `Z`-suffix
- RFC 5545 line folding (CRLF + whitespace continuation)

### The `ICalEvent` object

```php
$event->uid;         // string
$event->dtStart;     // DateTimeImmutable
$event->dtEnd;       // ?DateTimeImmutable
$event->summary;     // ?string
$event->description; // ?string
$event->location;    // ?string
$event->rrule;       // ?RecurrenceRule
$event->exDates;     // DateTimeImmutable[]
$event->isRecurring(); // bool
$event->occurrences($from, $to); // DateTimeImmutable[]
$event->toArray();   // array — ready for Day::$data
```

---

## Export

```php
use Tito10047\Calendar\ICal\ICalExporter;
use Tito10047\Calendar\Recurrence\RecurrenceRule;
use Tito10047\Calendar\Enum\DayName;

$ics = (new ICalExporter())
    ->calendarName('My Calendar')
    ->addEvent(
        title: 'Team meeting',
        from:  new DateTimeImmutable('2024-11-05T09:00:00Z'),
        to:    new DateTimeImmutable('2024-11-05T10:00:00Z'),
    )
    ->addRecurringEvent(
        title: 'Weekly standup',
        rule:  RecurrenceRule::weekly()->onDays(DayName::Monday),
        start: new DateTimeImmutable('2024-11-04T09:00:00Z'),
    )
    ->export(); // string — valid RFC 5545 document

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="calendar.ics"');
echo $ics;
```

The exporter is immutable — each `add*()` call returns a new instance. Lines are folded at 75 octets per the RFC.

### Re-exporting parsed events

```php
$parser = new ICalParser();
$events = $parser->parseFile('input.ics');

$exporter = new ICalExporter();
foreach ($events as $event) {
    $exporter = $exporter->addICalEvent($event);
}
file_put_contents('output.ics', $exporter->export());
```
