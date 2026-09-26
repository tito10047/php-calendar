# CalDAV server

`CalDavServer` turns your application into a CalDAV endpoint (RFC 4791). Once configured, Apple Calendar, Thunderbird, DAVx5, and any other CalDAV-capable client can connect, browse, create, edit, and delete events — all stored by your own backend.

---

## How it works

```
CalDAV client (Apple Calendar / Thunderbird / DAVx5)
    │
    ├─ OPTIONS   /caldav/           → capabilities (DAV: calendar-access)
    ├─ PROPFIND  /caldav/           → calendar name, supported component set
    ├─ REPORT    /caldav/           → list events in a date range
    ├─ GET       /caldav/{uid}.ics  → download a single event
    ├─ PUT       /caldav/{uid}.ics  → create or update an event
    └─ DELETE    /caldav/{uid}.ics  → delete an event
```

`CalDavServer` handles all of the above. It uses `ICalParser` to parse incoming requests and `ICalExporter` to serialise outgoing events. You implement one interface that bridges the server to your storage backend.

---

## The interface

```php
use Tito10047\Calendar\Server\CalendarEventStoreInterface;
use Tito10047\Calendar\ICal\ICalEvent;

interface CalendarEventStoreInterface
    extends CalendarEventWriterInterface, CalendarEventReaderInterface
{
    // from CalendarEventWriterInterface
    public function putEvent(string $uid, ICalEvent $event): void;
    public function deleteEvent(string $uid): void;

    // from CalendarEventReaderInterface
    public function getEvent(string $uid): ?ICalEvent;

    /** @return list<ICalEvent> */
    public function listEvents(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
```

`listEvents()` should return **master** events (with RRULE intact), not expanded occurrences — CalDAV clients handle their own recurrence expansion.

---

## Two-way store (recommended pattern)

Implement both `CalendarEventStoreInterface` and `DayDataLoaderInterface` in one class. The same object drives the CalDAV server (write side) and `Calendar::getDaysTable()` (read side):

```php
use Tito10047\Calendar\Server\CalendarEventStoreInterface;
use Tito10047\Calendar\Interface\DayDataLoaderInterface;
use Tito10047\Calendar\ICal\ICalEvent;

class DatabaseCalendarStore implements CalendarEventStoreInterface, DayDataLoaderInterface
{
    private array $byDate = [];

    public function __construct(private readonly \PDO $pdo) {}

    // ── CalendarEventWriterInterface ──────────────────────────────────────────

    public function putEvent(string $uid, ICalEvent $event): void
    {
        $this->pdo->prepare(
            'INSERT INTO calendar_events (uid, dt_start, dt_end, summary, description, rrule, color)
             VALUES (:uid, :start, :end, :summary, :desc, :rrule, :color)
             ON DUPLICATE KEY UPDATE dt_start=VALUES(dt_start), dt_end=VALUES(dt_end),
             summary=VALUES(summary), description=VALUES(description),
             rrule=VALUES(rrule), color=VALUES(color)'
        )->execute([
            'uid'     => $uid,
            'start'   => $event->dtStart->format('Y-m-d H:i:s'),
            'end'     => $event->dtEnd?->format('Y-m-d H:i:s'),
            'summary' => $event->summary,
            'desc'    => $event->description,
            'rrule'   => $event->rrule?->toRruleString(),
            'color'   => $event->color,
        ]);
    }

    public function deleteEvent(string $uid): void
    {
        $this->pdo->prepare('DELETE FROM calendar_events WHERE uid = ?')->execute([$uid]);
    }

    // ── CalendarEventReaderInterface ──────────────────────────────────────────

    public function getEvent(string $uid): ?ICalEvent
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_events WHERE uid = ?');
        $stmt->execute([$uid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->rowToEvent($row) : null;
    }

    /** @return list<ICalEvent> */
    public function listEvents(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_events WHERE dt_start <= ? AND (dt_end >= ? OR dt_end IS NULL OR rrule IS NOT NULL)'
        );
        $stmt->execute([$to->format('Y-m-d'), $from->format('Y-m-d')]);
        return array_map([$this, 'rowToEvent'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // ── DayDataLoaderInterface ────────────────────────────────────────────────

    public function load(\DateTimeImmutable $from, \DateTimeImmutable $to): static
    {
        $clone = clone $this;
        $clone->byDate = [];
        foreach ($this->listEvents($from, $to) as $event) {
            foreach ($event->expandOccurrences($from, $to) as $occurrence) {
                $clone->byDate[$occurrence->dtStart->format('Y-m-d')][] = $occurrence;
            }
        }
        return $clone;
    }

    public function getData(\DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function rowToEvent(array $row): ICalEvent
    {
        return new ICalEvent(
            uid:     $row['uid'],
            dtStart: new \DateTimeImmutable($row['dt_start']),
            dtEnd:   $row['dt_end'] !== null ? new \DateTimeImmutable($row['dt_end']) : null,
            summary: $row['summary'],
            description: $row['description'],
            location: null,
            rrule:   $row['rrule'] !== null
                ? \Tito10047\Calendar\Recurrence\RecurrenceRule::fromRrule($row['rrule'])
                : null,
            color:   $row['color'],
        );
    }
}
```

Usage:

```php
$store = new DatabaseCalendarStore($pdo);

// CalDAV server side — events come IN from clients
$server = new \Tito10047\Calendar\Server\CalDavServer($store, 'My Calendar');

// Grid rendering side — events go OUT to the template
$calendar = \Tito10047\Calendar\Calendar::forMonth(2025, 7)
    ->setDataLoader($store);
```

---

## Symfony integration

### 1. Routes

```yaml
# config/routes/caldav.yaml
caldav_collection:
    path:    /caldav/
    methods: [OPTIONS, PROPFIND, REPORT]
    defaults: { _controller: App\Controller\CalDavController::collection }

caldav_event:
    path:    /caldav/{uid}.ics
    methods: [OPTIONS, GET, PUT, DELETE]
    defaults: { _controller: App\Controller\CalDavController::event }
```

### 2. Controller

```php
// src/Controller/CalDavController.php
namespace App\Controller;

use App\Service\DatabaseCalendarStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tito10047\Calendar\Server\CalDavServer;
use Tito10047\Calendar\Server\CalDavResponse;

class CalDavController extends AbstractController
{
    public function __construct(private readonly DatabaseCalendarStore $store) {}

    public function collection(Request $request): Response
    {
        return $this->respond(
            (new CalDavServer($this->store, 'My Calendar'))->handleRequest(
                method:  $request->getMethod(),
                uid:     '',
                body:    $request->getContent(),
                headers: ['Depth' => $request->headers->get('Depth', '0')],
            )
        );
    }

    public function event(Request $request, string $uid): Response
    {
        return $this->respond(
            (new CalDavServer($this->store, 'My Calendar'))->handleRequest(
                method:  $request->getMethod(),
                uid:     $uid,
                body:    $request->getContent(),
                headers: [],
            )
        );
    }

    private function respond(CalDavResponse $r): Response
    {
        return new Response(
            $r->body,
            $r->statusCode,
            array_merge(['Content-Type' => $r->contentType], $r->headers),
        );
    }
}
```

### 3. Register the store as a service

```yaml
# config/services.yaml
services:
    App\Service\DatabaseCalendarStore:
        arguments: ['@database_connection']
```

### 4. Connect Apple Calendar

Open Apple Calendar → File → New Calendar Subscription and point it at `https://yourapp.com/caldav/` using CalDAV (not iCal subscription). Enter your credentials. Done.

## Laravel integration

### 1. Routes

```php
// routes/web.php
use App\Http\Controllers\CalDavController;

Route::match(
    ['OPTIONS', 'PROPFIND', 'REPORT'],
    '/caldav/',
    [CalDavController::class, 'collection']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::match(
    ['OPTIONS', 'GET', 'PUT', 'DELETE'],
    '/caldav/{uid}.ics',
    [CalDavController::class, 'event']
)->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
```

CSRF middleware must be excluded for CalDAV routes because CalDAV clients do not send CSRF tokens.

### 2. Controller

```php
// app/Http/Controllers/CalDavController.php
namespace App\Http\Controllers;

use App\Services\DatabaseCalendarStore;
use Illuminate\Http\Request;
use Tito10047\Calendar\Server\CalDavServer;
use Tito10047\Calendar\Server\CalDavResponse;

class CalDavController extends Controller
{
    public function __construct(private readonly DatabaseCalendarStore $store) {}

    public function collection(Request $request): \Illuminate\Http\Response
    {
        return $this->respond(
            (new CalDavServer($this->store, config('app.name') . ' Calendar'))->handleRequest(
                method:  $request->method(),
                uid:     '',
                body:    $request->getContent(),
                headers: ['Depth' => $request->header('Depth', '0')],
            )
        );
    }

    public function event(Request $request, string $uid): \Illuminate\Http\Response
    {
        return $this->respond(
            (new CalDavServer($this->store, config('app.name') . ' Calendar'))->handleRequest(
                method:  $request->method(),
                uid:     $uid,
                body:    $request->getContent(),
                headers: [],
            )
        );
    }

    private function respond(CalDavResponse $r): \Illuminate\Http\Response
    {
        return response($r->body, $r->statusCode)
            ->withHeaders(array_merge(['Content-Type' => $r->contentType], $r->headers));
    }
}
```

### 3. Register the service

```php
// app/Providers/AppServiceProvider.php
use App\Services\DatabaseCalendarStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseCalendarStore::class, function () {
            return new DatabaseCalendarStore(app('db')->getPdo());
        });
    }
}
```

## Plain PHP

```php
// public/caldav/index.php  (Apache rewrite or nginx try_files → this script)

require __DIR__ . '/../../vendor/autoload.php';

$store  = new MyCalendarStore();   // implements CalendarEventStoreInterface
$server = new \Tito10047\Calendar\Server\CalDavServer($store, 'My Calendar', '/caldav/');

$method  = $_SERVER['REQUEST_METHOD'];
$uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uid     = pathinfo(basename($uri), PATHINFO_FILENAME);   // strip .ics extension
$body    = file_get_contents('php://input');
$headers = ['Depth' => $_SERVER['HTTP_DEPTH'] ?? '0'];

$r = $server->handleRequest($method, $uid, $body, $headers);

http_response_code($r->statusCode);
header('Content-Type: ' . $r->contentType);
foreach ($r->headers as $name => $value) {
    header("$name: $value");
}
echo $r->body;
```

Nginx config:

```nginx
location /caldav/ {
    try_files $uri $uri/ /caldav/index.php?$query_string;
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/caldav/index.php;
    include fastcgi_params;
}
```

---

## Connecting CalDAV clients

### Apple Calendar (macOS / iOS)

1. macOS: Calendar → File → New Calendar Subscription → enter `https://yourapp.com/caldav/`
   Or: System Settings → Internet Accounts → Add Account → Other (CalDAV)
2. Server address: `https://yourapp.com/caldav/`
3. Username / password: your app credentials

### Thunderbird

Tools → Account Settings → Calendar → Add calendar → On the network → CalDAV
URL: `https://yourapp.com/caldav/`

### DAVx5 (Android)

Add account → URL + credentials → `https://yourapp.com/caldav/`
DAVx5 discovers all available calendars automatically via PROPFIND.

---

## Security

CalDAV endpoints receive unauthenticated HTTP requests from clients — always protect them:

**Symfony:** Use `access_control` in `security.yaml`:
```yaml
# config/packages/security.yaml
access_control:
    - { path: ^/caldav, roles: ROLE_USER }
```

**Laravel:** Apply `auth` middleware to the CalDAV route group:
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::match(['OPTIONS', 'PROPFIND', 'REPORT'], '/caldav/', ...);
    Route::match(['OPTIONS', 'GET', 'PUT', 'DELETE'], '/caldav/{uid}.ics', ...);
});
```

**Plain PHP:**
```php
if (!isset($_SERVER['PHP_AUTH_USER']) || !verifyCredentials($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="Calendar"');
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
```

---

## Reference

| Class / Interface | Namespace | Purpose |
|---|---|---|
| `CalDavServer` | `Tito10047\Calendar\Server` | Protocol handler — instantiate per request |
| `CalDavResponse` | `Tito10047\Calendar\Server` | Immutable HTTP response value object |
| `CalendarEventStoreInterface` | `Tito10047\Calendar\Server` | Combined read+write — implement this |
| `CalendarEventWriterInterface` | `Tito10047\Calendar\Server` | Write-only subset (`putEvent`, `deleteEvent`) |
| `CalendarEventReaderInterface` | `Tito10047\Calendar\Server` | Read-only subset (`getEvent`, `listEvents`) |
| `ICalParser` | `Tito10047\Calendar\ICal` | Parses incoming PUT bodies |
| `ICalExporter` | `Tito10047\Calendar\ICal` | Serialises events for GET / REPORT responses |
