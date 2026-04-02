# Laravel WebSockets

> [!NOTE]
> This package is actively maintained as a fork of beyondcode/laravel-websockets.

Plug-and-play WebSockets for Laravel with a Pusher-compatible protocol, a fast fork-based handler, and practical helpers for broadcasting and testing.

## Why this package

- Drop-in broadcasting backend for Laravel apps that already use Echo/Pusher-compatible clients
- Fast local handler with async processing via `pcntl_fork`
- Protocol compatibility for both modern `websocket.*` and legacy `pusher:*` action formats
- Built-in developer ergonomics: helper functions, service methods, and rich test helpers

## Install in 2 minutes

1. Install package

```bash
composer require blax-software/laravel-websockets
```

2. Publish config

```bash
php artisan vendor:publish --provider="BlaxSoftware\\LaravelWebSockets\\WebSocketsServiceProvider" --tag="config"
```

3. Start server

```bash
php artisan websockets:serve
```

Default server URL is `ws://127.0.0.1:6001`.

## Helper functions (broadcast from anywhere)

The package ships with global helpers in `src/helpers_global.php`.

```php
// Broadcast to everyone on a channel
ws_broadcast('chat.message', ['text' => 'Hello'], 'chat');

// Whisper to specific socket IDs only
ws_whisper('chat.typing', ['typing' => true], ['1234.1', '1234.2'], 'chat');

// Broadcast to everyone except listed sockets
ws_broadcast_except('chat.message', ['text' => 'Server msg'], ['1234.1'], 'chat');

// Check if local unix-socket broadcaster is available
if (ws_available()) {
	ws_broadcast('app.health', ['ok' => true]);
}

// Build protocol auth payload for private/presence channels
$auth = wsSession('private-updates', ['user_id' => 7, 'user_info' => ['name' => 'Jane']]);
```

## Service API

Use the service directly when you prefer explicit class calls over helpers.

```php
use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;

WebsocketService::send('metrics.tick', ['count' => 1], 'websocket');
WebsocketService::whisper('chat.typing', ['typing' => true], ['1234.1'], 'chat');
WebsocketService::broadcastExcept('chat.message', ['text' => 'Hi'], ['1234.1'], 'chat');

// Optional in-process tracking helpers
WebsocketService::setUserAuthed($socketId, $userId);
$authed = WebsocketService::getAuthedUsers();
```

## Testing experience

The test suite includes helper-first patterns so WebSocket tests stay short and readable.

### Test helpers

- `newConnection()`
- `newActiveConnection(['channel'])`
- `newPrivateConnection('private-channel')`
- `newPresenceConnection('presence-channel', ['user_id' => 1, 'user_info' => [...]])`

### Example

```php
$connection = $this->newActiveConnection(['chat']);

$this->wsHandler->onMessage($connection, new Message([
	'event' => 'websocket.ping',
	'data' => new stdClass(),
]));

$connection->assertSentEvent('websocket.pong');
```

Run tests:

```bash
vendor/bin/phpunit --exclude-group=stability,stress,integration,requires-server
```

## Documentation

- Main docs: [docs](docs)
- Getting started: [docs/getting-started/introduction.md](docs/getting-started/introduction.md)
- Helper & testing guide: [docs/advanced-usage/helpers-and-testing.md](docs/advanced-usage/helpers-and-testing.md)

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security

Please report vulnerabilities via issue tracker or by email: office@blax.at.

## Credits

- [Marcel Pociot](https://github.com/mpociot)
- [Freek Van der Herten](https://github.com/freekmurze)
- [All Contributors](../../contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).
