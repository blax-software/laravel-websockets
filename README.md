[![Blax Software OSS](https://raw.githubusercontent.com/blax-software/laravel-workkit/master/art/oss-initiative-banner.svg)](https://github.com/blax-software)

# Laravel WebSockets

[![PHP Version](https://img.shields.io/badge/php-%5E8.1-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-9.x--12.x-orange)](https://laravel.com)

Plug-and-play WebSockets for Laravel with a Pusher-compatible protocol, async fork-based handling, attribute-driven routing, and live operational tooling.

> [!NOTE]
> This package is actively maintained as a fork of beyondcode/laravel-websockets.

## Features

- **`#[Websocket]` attribute on regular HTTP controllers** — turn any controller method into a WebSocket-callable endpoint with one annotation, no second class to maintain
- **Async processing** — incoming messages are handled in `pcntl_fork` child processes, so a slow handler never blocks the event loop
- **Broadcast from anywhere** — `ws_broadcast()`, `ws_whisper()`, `ws_broadcast_except()` helpers and a static `WebsocketService` API let any controller, job, or service push events to any channel
- **Multiple channel types** — public, `private-*`, `presence-*`, and `open-presence-*` channels with the standard auth handshake
- **Live ops tooling** — `php artisan websockets:watch` renders connection counts, authenticated users, and per-channel connections, refreshing every second:

  ```
  WebSocket Server — Live Stats
  2026-05-05 14:33:35 — refreshing every 1s (Ctrl+C to exit)

    Live Stats .......................................................................................................................................
    Total connections ............................................................................................................................. 12
    Authenticated users ............................................................................................................................ 3
    Active channels ................................................................................................................................ 1

  +-----------+-------------+
  | Channel   | Connections |
  +-----------+-------------+
  | websocket | 12          |
  +-----------+-------------+
  ```

- **Automatic route recognition** — controller class and method names map to event names automatically (`FlightschoolController::index` → `flightschool.index`); override per method or per class via `#[Websocket(event: ..., prefix: ..., suffix: ..., needAuth: true)]`
- **Hot code reload in dev, OPcache in prod** — `websocket:steer cache:clear` clears OPcache and the controller resolver cache without restarting the running server, so iteration is instant in development while production runs with fully warmed caches
- **Pusher-compatible protocol** — supports both modern `websocket.*` and legacy `pusher:*` action formats, drop-in for Echo and pusher-js clients
- **Test helpers** — `newConnection()`, `newActiveConnection()`, `newPrivateConnection()`, `newPresenceConnection()`, plus `assertSentEvent()` keep WebSocket tests short

## Requirements

- PHP 8.1+
- Laravel 9, 10, 11 or 12
- `ext-pcntl` (for async fork-based handling)

## Installation

```bash
composer require blax-software/laravel-websockets
```

Publish the config:

```bash
php artisan vendor:publish --provider="BlaxSoftware\LaravelWebSockets\WebSocketsServiceProvider" --tag="config"
```

Start the server:

```bash
php artisan websockets:serve
```

Default URL is `ws://127.0.0.1:6001`.

## Quick Start

### 1. Mark a regular controller method as WebSocket-reachable

```php
use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;

class FlightschoolController extends Controller
{
    #[Websocket]                      // event: "flightschool.index"
    public function index() { ... }

    #[Websocket(event: 'flightschools.list')]   // explicit override
    public function list() { ... }

    #[Websocket(needAuth: true)]      // requires authenticated socket
    public function update() { ... }
}
```

### 2. Broadcast from anywhere

```php
// Helpers
ws_broadcast('chat.message', ['text' => 'Hello'], 'chat');
ws_whisper('chat.typing', ['typing' => true], ['1234.1', '1234.2'], 'chat');
ws_broadcast_except('chat.message', ['text' => 'Server msg'], ['1234.1'], 'chat');

// Service API
use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;

WebsocketService::send('metrics.tick', ['count' => 1], 'websocket');
WebsocketService::broadcastExcept('chat.message', ['text' => 'Hi'], ['1234.1'], 'chat');
```

### 3. Build a private/presence auth payload

```php
$auth = wsSession('private-updates', [
    'user_id'   => 7,
    'user_info' => ['name' => 'Jane'],
]);
```

### 4. Watch live stats

```bash
php artisan websockets:watch          # connection counts and channels
php artisan websockets:watch -v       # expanded per-connection rows
php artisan websockets:info           # one-shot snapshot
```

### 5. Iterate without restarting

```bash
php artisan websocket:steer cache:clear   # clear OPcache + resolver cache
php artisan websockets:restart            # graceful restart
php artisan websocket:restart-hard        # signal-based force restart
```

## Channel Types

| Type             | Prefix             | Description                                                          |
|------------------|--------------------|----------------------------------------------------------------------|
| Public           | *(none)*           | Anyone can subscribe                                                 |
| Private          | `private-`         | Server-signed auth required                                          |
| Presence         | `presence-`        | Auth required, tracks user list, broadcasts join/leave               |
| Open Presence    | `open-presence-`   | Presence semantics without the auth signature — useful for guests   |

## Testing

```php
$connection = $this->newActiveConnection(['chat']);

$this->wsHandler->onMessage($connection, new Message([
    'event' => 'websocket.ping',
    'data'  => new stdClass(),
]));

$connection->assertSentEvent('websocket.pong');
```

```bash
vendor/bin/phpunit --exclude-group=stability,stress,integration,requires-server
```

## Documentation

- Main docs: [docs](docs)
- Getting started: [docs/getting-started/introduction.md](docs/getting-started/introduction.md)
- Helper & testing guide: [docs/advanced-usage/helpers-and-testing.md](docs/advanced-usage/helpers-and-testing.md)
- Custom handlers: [docs/advanced-usage/custom-websocket-handlers.md](docs/advanced-usage/custom-websocket-handlers.md)

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security

Please report vulnerabilities via the issue tracker or by email: office@blax.at.

## Credits

- [Marcel Pociot](https://github.com/mpociot)
- [Freek Van der Herten](https://github.com/freekmurze)
- [All Contributors](../../contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).

## Star History

<a href="https://www.star-history.com/?repos=blax-software%2Flaravel-websockets&type=date&legend=top-left">
 <picture>
   <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/chart?repos=blax-software/laravel-websockets&type=date&theme=dark&legend=top-left" />
   <source media="(prefers-color-scheme: light)" srcset="https://api.star-history.com/chart?repos=blax-software/laravel-websockets&type=date&legend=top-left" />
   <img alt="Star History Chart" src="https://api.star-history.com/chart?repos=blax-software/laravel-websockets&type=date&legend=top-left" />
 </picture>
</a>
