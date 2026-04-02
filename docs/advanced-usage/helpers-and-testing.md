---
title: Helpers and Testing
order: 2
---

# Helpers and Testing

This package is designed to be easy to use from both app code and test code.

## Global helpers

Global helpers live in `src/helpers_global.php` and call into the same core service used by the server.

### Broadcast to channel

```php
ws_broadcast('chat.message', ['text' => 'Hello world'], 'chat');
```

### Whisper to specific sockets

```php
ws_whisper('chat.typing', ['typing' => true], ['1234.1', '1234.2'], 'chat');
```

### Broadcast except sockets

```php
ws_broadcast_except('chat.message', ['text' => 'Server update'], ['1234.1'], 'chat');
```

### Runtime availability check

```php
if (ws_available()) {
    ws_broadcast('system.heartbeat', ['ok' => true]);
}
```

### Generate auth payload

```php
$auth = wsSession('presence-room', [
    'user_id' => 42,
    'user_info' => ['name' => 'Amelia'],
]);
```

Use this when you need to produce channel auth payloads in custom flows.

## WebsocketService class

If you prefer explicit classes over helpers:

```php
use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;

WebsocketService::send('chat.message', ['text' => 'Hello'], 'chat');
WebsocketService::whisper('chat.typing', ['typing' => true], ['1234.1'], 'chat');
WebsocketService::broadcastExcept('chat.message', ['text' => 'Hi'], ['1234.1'], 'chat');
```

### Tracking helpers

`WebsocketService` also exposes lightweight in-process tracking helpers:

- `setUserAuthed($socketId, $userId)`
- `clearUserAuthed($socketId)`
- `getAuth()`
- `getAuthedUsers()`
- `isUserConnected($userId)`
- `getUserSocketIds($userId)`
- `getActiveChannels()`
- `getChannelConnections($channel)`
- `resetAllTracking()`

## Testing with package helpers

The package test base class includes high-level helpers that make tests concise:

- `newConnection()`
- `newActiveConnection(['channel'])`
- `newPrivateConnection('private-channel')`
- `newPresenceConnection('presence-channel', ['user_id' => 1, 'user_info' => [...]])`

### Example ping/pong test

```php
$connection = $this->newActiveConnection(['websocket']);
$connection->resetEvents();

$this->wsHandler->onMessage($connection, new Message([
    'event' => 'websocket.ping',
    'data' => new stdClass(),
]));

$connection->assertSentEvent('websocket.pong');
```

### Example subscription test

```php
$connection = $this->newPrivateConnection('private-chat');
$connection->assertSentEvent('websocket_internal.subscription_succeeded');
```

## New reference test files

For complete examples, see:

- `tests/WebsocketServiceTest.php`
- `tests/HandlerLifecycleTest.php`

These files cover helper-first testing patterns and full handler lifecycle behavior.
