# CalDAV client

`CalDAVClient` is a minimal, fluent HTTP client for fetching events directly from a CalDAV server.
It uses Basic authentication and the standard `calendar-query` REPORT request defined in RFC 4791.

Compatible with: **Nextcloud**, **Apple iCloud**, **Fastmail**, **Radicale**, and any other
RFC 4791-compliant server. Google Calendar requires an app-specific password (OAuth not supported).

---

## Basic usage

```php
use Tito10047\Calendar\ICal\CalDAVClient;

$client = new CalDAVClient('https://cloud.example.com/remote.php/dav/calendars/jan/personal/');

$events = $client
    ->authenticate('jan', 'app-password')
    ->fetchEvents(
        new DateTimeImmutable('2025-01-01'),
        new DateTimeImmutable('2025-12-31'),
    );
// → list<ICalEvent>
```

The returned events are fully parsed — recurring events, RECURRENCE-ID overrides, alarms, attendees, X-* properties, and CalDAV metadata are all available.

---

## Methods

```php
// Constructor — base calendar URL (trailing slash optional)
$client = new CalDAVClient('https://cloud.example.com/remote.php/dav/calendars/user/personal/');

// Set credentials — returns a new instance (immutable)
$client = $client->authenticate('username', 'password-or-app-token');

// Override the default 30-second HTTP timeout
$client = $client->withTimeout(60);

// Fetch events in a date range — sends a REPORT request
$events = $client->fetchEvents($from, $to); // list<ICalEvent>

// List calendar URLs under the base URL — sends a PROPFIND request
$hrefs = $client->listCalendars(); // list<string>
```

---

## Nextcloud

```php
$client = new CalDAVClient('https://cloud.myserver.com/remote.php/dav/calendars/username/personal/');
$events = $client->authenticate('username', 'app-password')->fetchEvents($from, $to);
```

Generate an app password in Nextcloud under **Settings → Security → App passwords**.

---

## Apple iCloud

```php
$client = new CalDAVClient('https://caldav.icloud.com/');
$events = $client->authenticate('apple-id@icloud.com', 'app-specific-password')
                 ->fetchEvents($from, $to);
```

Generate an app-specific password at [appleid.apple.com](https://appleid.apple.com).

---

## Fastmail

```php
$client = new CalDAVClient('https://caldav.fastmail.com/dav/principals/user/you@fastmail.com/');
$events = $client->authenticate('you@fastmail.com', 'app-password')->fetchEvents($from, $to);
```

---

## Google Calendar

Google requires OAuth 2.0 for full access. For read-only use with a personal account, generate an
app password (requires 2FA enabled on the account) or use the public `.ics` URL with `ICalParser::parseUrl()` instead:

```php
// Simpler alternative for Google Calendar — no CalDAV needed
$events = (new \Tito10047\Calendar\ICal\ICalParser())
    ->parseUrl('https://calendar.google.com/calendar/ical/YOUR_CALENDAR_ID/basic.ics');
```

---

## listCalendars()

Sends a `PROPFIND` request with `Depth: 1` to discover calendar URLs under the base URL:

```php
$hrefs = $client->listCalendars();
// ['/remote.php/dav/calendars/jan/personal/', '/remote.php/dav/calendars/jan/work/', …]

// Fetch from a specific calendar
foreach ($hrefs as $href) {
    $calClient = new CalDAVClient('https://cloud.example.com' . $href);
    $events    = $calClient->authenticate('jan', 'pass')->fetchEvents($from, $to);
}
```

---

## Error handling

`fetchEvents()` and `listCalendars()` throw `RuntimeException` when the HTTP request fails
(network error, invalid URL, or authentication failure detected at the stream level).

HTTP-level errors (401 Unauthorized, 404 Not Found) from the server body are not automatically
detected — check the returned event count or log the response body if you suspect access issues.

```php
try {
    $events = $client->fetchEvents($from, $to);
} catch (\RuntimeException $e) {
    // Network failure or unreachable server
    $logger->error('CalDAV fetch failed: ' . $e->getMessage());
    $events = [];
}
```

---

## Combining with the calendar grid

```php
$events   = $client->authenticate('user', 'pass')->fetchEvents($from, $to);
$calendar = Calendar::forMonth(2025, 6)
    ->setDataLoader(\Tito10047\Calendar\ICal\ICalDataLoader::fromEvents($events));
```

Or serve as a JSON feed:

```php
echo \Tito10047\Calendar\Serializer\JsonSerializer::fromEvents($events)
    ->forRange($from, $to)
    ->toJson();
```
