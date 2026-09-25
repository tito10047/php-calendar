# iCal import and export

The library includes a full RFC 5545 iCal parser and a fluent, immutable exporter.
Both are zero-dependency and integrate directly with the calendar via `DayDataLoaderInterface`.

---

## Import

### From a URL, file, or string

```php
use Tito10047\Calendar\ICal\ICalParser;

$parser = new ICalParser();

$events = $parser->parseUrl('https://calendar.google.com/calendar/ical/.../basic.ics');
$events = $parser->parseFile('/path/to/calendar.ics');
$events = $parser->parseString($icsContent);
// → list<ICalEvent>

// Options: size limit (bytes), HTTP timeout (seconds), zone for floating times and DATE values
$parser = new ICalParser(
    maxBytes:        10 * 1024 * 1024,              // default 10 MiB
    timeout:         10,                            // default 10 s
    defaultTimezone: new DateTimeZone('Europe/Bratislava'), // default: date_default_timezone_get()
);
```

`parseUrl()` accepts only `http://` and `https://` URLs (`webcal://` is rewritten to `https://`), enforces the
size limit, requires a 2xx status, and never puts URL credentials into exception messages.
`parseFile()` is meant for **trusted local paths** — never pass user input to it; use `parseUrl()` for remote feeds.

The parser is forgiving: a broken or unsupported `RRULE` does not abort the feed — the event is kept as a
non-recurring event.

### Attach to a calendar grid

```php
use Tito10047\Calendar\ICal\ICalDataLoader;

$loader   = ICalDataLoader::fromEvents($events);
$calendar = Calendar::forMonth(2024, 11)->setDataLoader($loader);
// Each Day now has $day->data populated with ICalEvent objects for that date
```

`ICalDataLoader` calls `ICalEvent::expandOccurrences($from, $to)` per event — recurring events are expanded lazily, only for the visible range.
`$day->data` holds **per-instance** `ICalEvent`s (the instance's own `dtStart` / `dtEnd`, RECURRENCE-ID overrides applied).
Multi-day events appear on every day they cover (for all-day events `DTEND` is exclusive), and timed events are
bucketed by day in the calendar's timezone.

### What the parser handles

| Property | Notes |
|----------|-------|
| `VEVENT` | one-time and recurring events |
| `VTODO` | task/todo components (via `parseTodos()`) |
| `VALARM` | display, audio, and email reminders |
| `RRULE` | full recurrence — see [recurrence.md](recurrence.md); a broken/unsupported rule leaves the event non-recurring |
| `EXDATE` | excluded dates (also comma-separated lists) |
| `RDATE` | extra explicit occurrence dates — also without an `RRULE`, and `VALUE=PERIOD` (start is used) |
| `RECURRENCE-ID` | modified occurrences, incl. `RANGE=THISANDFUTURE`; overrides without a recurring master are returned as standalone events |
| `DTSTART`, `DTEND`, `DURATION` | event time bounds; `VALUE=DATE` → all-day event (`allDay = true`) at midnight in the default timezone |
| `SUMMARY`, `DESCRIPTION`, `LOCATION`, `URL` | basic metadata |
| `COLOR`, `CATEGORIES`, `STATUS` | visual / scheduling metadata |
| `TRANSP`, `CLASS`, `PRIORITY` | free/busy, visibility, priority |
| `ORGANIZER`, `ATTENDEE` | meeting invitations |
| `DTSTAMP`, `CREATED`, `LAST-MODIFIED`, `SEQUENCE` | CalDAV sync metadata |
| `X-*` extension properties | preserved and re-exported |
| `VTIMEZONE` | timezone resolution; TZID params (also via `X-LIC-LOCATION`, offset fallback); UTC `Z`-suffix |
| RFC 5545 line folding | CRLF + whitespace continuation unfolded automatically |
| TEXT escaping | `\,` `\;` `\n` `\\` unescaped |
| Parameters | DQUOTE-quoted values (`CN="Doe, John"`), RFC 6868 `^`-escapes |
| Misc | case-insensitive `BEGIN`/`END`, UTF-8 BOM |

---

## The `ICalEvent` object

```php
// Core
$event->uid;                  // string
$event->dtStart;              // DateTimeImmutable
$event->dtEnd;                // ?DateTimeImmutable  (exclusive end date when allDay)
$event->allDay;               // bool — DATE-valued DTSTART
$event->summary;              // ?string
$event->description;          // ?string
$event->location;             // ?string
$event->url;                  // ?string
$event->rrule;                // ?RecurrenceRule
$event->exDates;              // list<DateTimeImmutable>

// Visual / scheduling
$event->color;                // ?string  (e.g. '#e74c3c' or 'red')
$event->categories;           // list<string>
$event->status;               // ?EventStatus  (Confirmed | Tentative | Cancelled)
$event->transp;               // ?EventTransp  (Opaque | Transparent)
$event->classification;       // ?EventClass   (Public | Private | Confidential)
$event->priority;             // int  (0 = undefined, 1 = highest, 9 = lowest)

// Meeting invitations
$event->organizer;            // ?string  (email)
$event->organizerName;        // ?string
$event->attendees;            // list<Attendee>
$event->alarms;               // list<VAlarm>

// RECURRENCE-ID overrides
$event->recurrenceId;         // ?DateTimeImmutable  (set on override events and expanded instances)
$event->thisAndFuture;        // bool — RECURRENCE-ID;RANGE=THISANDFUTURE
$event->modifiedOccurrences;  // array<Y-m-d, ICalEvent>  (on master event)

// CalDAV sync metadata
$event->dtStamp;              // ?DateTimeImmutable
$event->created;              // ?DateTimeImmutable
$event->lastModified;         // ?DateTimeImmutable
$event->sequence;             // int

// X-* extension properties
$event->extensionProperties;  // array<string, string>
$event->getExtendedProperty('X-GOOGLE-CONFERENCE'); // ?string

// Helpers
$event->isRecurring();        // bool
$event->getEnd();             // ?DateTimeImmutable — exclusive end (dtEnd)
$event->getEnd($start);       // ?DateTimeImmutable — end of the instance starting at $start
$event->withDates($start, $end); // ICalEvent copy with different start / end
$event->occurrences($from, $to);        // list<DateTimeImmutable>  — instance START datetimes
$event->expandOccurrences($from, $to);  // list<ICalEvent>  — per-instance objects, RECURRENCE-ID applied
$event->toArray();            // array — ready for Day::$data
```

### `occurrences()` and `expandOccurrences()`

Both return every instance that **overlaps** the days `[from, to]` (both inclusive):

- `occurrences()` returns the instances' **start datetimes** (with the event's time and timezone — not midnight dates),
  including RECURRENCE-ID overrides at their moved time.
- `expandOccurrences()` returns one `ICalEvent` per instance: the instance's own `dtStart` / `dtEnd`,
  `recurrenceId` = the original start, and `rrule = null`.
- Events that started before `$from` but are still running (multi-day) are included. EXDATEs are removed, RDATEs added.
- Day boundaries use `$from`'s timezone for timed events and the event's own timezone for all-day events.

```php
$event->occurrences(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-31'));
// [2025-01-06 09:00 Europe/Bratislava, 2025-01-13 10:30 (moved override), ...]
```

---

## RECURRENCE-ID — modified occurrences

When a CalDAV server (or an `.ics` file) contains an override for a single occurrence, the parser
automatically attaches it to the master event:

```php
$events = (new ICalParser())->parseString($ics);
// Returns only master events — overrides are merged in via withModifiedOccurrence()

$master = $events[0];
$master->modifiedOccurrences; // array<'2025-01-13', ICalEvent>

// expandOccurrences() substitutes the override transparently
$occurrences = $master->expandOccurrences($from, $to);
// list<ICalEvent> — the overridden occurrence shows its new time/title
```

If the override's new date is within `[from, to]` but the original date is outside, it is still included.
An override with `RANGE=THISANDFUTURE` (`$override->thisAndFuture === true`) also applies to all later instances.
An override whose master is not in the feed (e.g. a single-instance invitation) is returned as a standalone event.

---

## Colors, categories, and status

```php
use Tito10047\Calendar\Enum\EventStatus;

$event->color;      // '#e74c3c' or CSS color name
$event->categories; // ['Work', 'Meeting']
$event->status;     // EventStatus::Confirmed | Tentative | Cancelled
```

In templates:

```twig
<div class="event {{ event.status ? event.status.value|lower : '' }}"
     style="background: {{ event.color ?? '#3788d8' }}">
    {{ event.summary }}
    {% for cat in event.categories %}
        <span class="badge">{{ cat }}</span>
    {% endfor %}
</div>
```

---

## VALARM — alarms and reminders

Parsed alarms are available on each event:

```php
foreach ($event->alarms as $alarm) {
    echo $alarm->action;      // 'DISPLAY' | 'AUDIO' | 'EMAIL'
    echo $alarm->trigger;     // '-PT15M' (ISO 8601 duration)
    echo $alarm->description; // ?string
    echo $alarm->summary;     // ?string (EMAIL action subject)
}
```

Build alarms fluently when creating events:

```php
use Tito10047\Calendar\ICal\VAlarm;

$alarm1 = VAlarm::display('-PT15M', 'Reminder: ' . $title);
$alarm2 = VAlarm::email('-P1D', 'Tomorrow: ' . $title, 'Full details here');
$alarm3 = VAlarm::audio('-PT5M');

$event = $event->withAlarm($alarm1)->withAlarm($alarm2);
```

---

## ORGANIZER and ATTENDEE — meeting invitations

```php
use Tito10047\Calendar\ICal\Attendee;

$event = $event
    ->withOrganizer('jan@firma.sk', 'Ján Novák')
    ->withAttendee(Attendee::required('maria@firma.sk', 'Mária Horáková'))
    ->withAttendee(Attendee::optional('peter@firma.sk', 'Peter Kováč'));

// Parsed from iCal:
$event->organizer;     // 'jan@firma.sk'
$event->organizerName; // 'Ján Novák'

foreach ($event->attendees as $att) {
    echo $att->email;    // 'maria@firma.sk'
    echo $att->name;     // 'Mária Horáková'
    echo $att->role;     // 'REQ-PARTICIPANT' | 'OPT-PARTICIPANT' | …
    echo $att->partStat; // 'NEEDS-ACTION' | 'ACCEPTED' | 'DECLINED' | …
    echo $att->rsvp;     // bool
}
```

The exporter generates `METHOD:PUBLISH` by default. Change it to `METHOD:REQUEST` manually when building invitation `.ics` files.

---

## X-* extension properties

Non-standard properties (Google, Apple, etc.) are preserved through parse → export:

```php
// After parsing
$event->getExtendedProperty('X-GOOGLE-CALENDAR-CONTENT-DISPLAY'); // 'chip'
$event->getExtendedProperty('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR');  // 'AUTOMATIC'
$event->extensionProperties; // array<string, string> — all X-* props

// When building events manually
$event = new ICalEvent(
    // ...
    extensionProperties: ['X-CUSTOM-SYSTEM' => 'my-value'],
);
```

---

## TRANSP, CLASS, PRIORITY

```php
use Tito10047\Calendar\Enum\EventTransp;
use Tito10047\Calendar\Enum\EventClass;

$event->transp;         // EventTransp::Opaque | Transparent
$event->classification; // EventClass::Public | Private | Confidential
$event->priority;       // int 0–9 (0 = undefined)

// Build:
$event = new ICalEvent(
    // ...
    transp:         EventTransp::Transparent,   // does not block time
    classification: EventClass::Private,
    priority:       1,                          // highest priority
);
```

---

## CalDAV metadata (DTSTAMP, CREATED, LAST-MODIFIED, SEQUENCE)

These fields are used by CalDAV servers to track revision history. They are parsed, preserved, and exported:

```php
$event->dtStamp;      // ?DateTimeImmutable — when this iCal object was created
$event->created;      // ?DateTimeImmutable — when the event itself was created
$event->lastModified; // ?DateTimeImmutable — last change
$event->sequence;     // int — revision counter, incremented on each change
```

When re-exporting, set `sequence` to the next revision number to signal an update to CalDAV clients.

---

## VTODO — tasks

```php
$todos = (new ICalParser())->parseTodos($icsContent);
// → list<ICalTodo>

foreach ($todos as $todo) {
    $todo->uid;             // string
    $todo->summary;         // ?string
    $todo->description;     // ?string
    $todo->due;             // ?DateTimeImmutable
    $todo->dtStart;         // ?DateTimeImmutable
    $todo->status;          // string ('NEEDS-ACTION', 'COMPLETED', 'IN-PROCESS', 'CANCELLED')
    $todo->priority;        // int
    $todo->percentComplete; // int 0–100
    $todo->isCompleted();   // bool
}
```

---

## Export

### Basic usage

```php
use Tito10047\Calendar\ICal\ICalExporter;

$ics = (new ICalExporter())
    ->calendarName('My Calendar')
    ->addEvent(
        title:       'Team meeting',
        from:        new DateTimeImmutable('2024-11-05T09:00:00Z'),
        to:          new DateTimeImmutable('2024-11-05T10:00:00Z'),
        description: 'Q4 planning',
        url:         'https://meet.example.com/abc',
        color:       '#e74c3c',
        categories:  ['Work', 'Planning'],
        status:      EventStatus::Confirmed,
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

The exporter is immutable — each `add*()` call returns a new instance. Lines are folded at 75 octets per the RFC (UTF-8 safe — never inside a multi-byte character).

All-day events and series — pass `allDay: true` (dates are written as `VALUE=DATE`, `to` / `end` is the exclusive end date):

```php
$exporter = $exporter
    ->addEvent(
        title:  'Conference',
        from:   new DateTimeImmutable('2025-06-02'),
        to:     new DateTimeImmutable('2025-06-05'), // exclusive — covers 2–4 June
        allDay: true,
    )
    ->addRecurringEvent(
        title:  'Standup',
        rule:   RecurrenceRule::weekly()->onDays(DayName::Monday)->limitTo(10),
        start:  new DateTimeImmutable('2025-06-02 09:00', new DateTimeZone('Europe/Bratislava')),
        end:    new DateTimeImmutable('2025-06-02 09:15', new DateTimeZone('Europe/Bratislava')),
    );
```

Every value is escaped / quoted (TEXT escaping, quoted parameters, control characters stripped), so untrusted input
cannot inject properties or components via CR/LF or `;`/`:`. `EXDATE`/`RDATE` and RECURRENCE-ID override VEVENTs are exported,
and `UNTIL` is written as a DATE for all-day series. The `PRODID` is `-//php-calendar//php-calendar 3.0//EN`.

### Re-exporting parsed events

```php
$events = (new ICalParser())->parseFile('input.ics');

$exporter = new ICalExporter();
foreach ($events as $event) {
    $exporter = $exporter->addICalEvent($event);
}
file_put_contents('output.ics', $exporter->export());
```

All fields are round-trip safe: colors, categories, alarms, attendees, X-* properties, CalDAV metadata — everything parsed is exported back.

### Streaming export (large calendars)

For calendars with thousands of events, avoid building the entire string in memory:

```php
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="calendar.ics"');

$stream = fopen('php://output', 'w');
$exporter->exportToStream($stream);
fclose($stream);
```

`exportToStream()` writes folded RFC 5545 lines one-by-one to any writable PHP stream resource.

---

## Timezone handling

| Input format | Parsed as |
|---|---|
| `20241101T120000Z` | UTC |
| `20241101T120000` with `TZID=Europe/Berlin` param | Europe/Berlin |
| `VTIMEZONE` block with named TZID | resolved to PHP `DateTimeZone` |
| `VTIMEZONE` block with unknown TZID | resolved via `X-LIC-LOCATION`, else falls back to `TZOFFSETTO` offset |
| `20241101T120000` (floating, no TZID) | default timezone (constructor argument) |
| `20241101` (`VALUE=DATE`) | all-day event (`allDay = true`), midnight in the default timezone |

On export, UTC datetimes get the `Z` suffix; named IANA timezones get `TZID=…` parameter plus a matching `VTIMEZONE`
component (disable with `withTimezoneDefinitions(false)`); numeric offsets (`+01:00`) are normalised to UTC.
`DTSTAMP`, `CREATED` and `LAST-MODIFIED` are always written in UTC; all-day events as `VALUE=DATE`.
