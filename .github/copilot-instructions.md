# laravel-websockets

## Overview
WebSocket server for Laravel, Pusher-compatible. Ratchet-based, runs as a long-lived artisan command via supervisor.

## Architecture
- `src/Server/WebSocketHandler.php` — main Ratchet handler (onOpen/onMessage/onClose/onError)
- `src/Websocket/Handler.php` — higher-level handler with app verification, channel management
- `src/Server/Loggers/` — decorator loggers (HttpLogger, ConnectionLogger, WebSocketsLogger)
- `src/Console/Commands/StartServer.php` — artisan command that boots the event loop

## Logging
- Auto-registers a `websocket` log channel (daily, `storage/logs/websocket.log`, 7-day retention)
- `wsLog()` helper in WebSocketHandler for structured server-side logging
- Logger base class persists to file via `fileLog()` in addition to console output

## Configuration
- PUSHER_APP_KEY should always be `websocket` — generic identifier, not environment-specific
- Port 6001 (supervisor), Traefik terminates TLS in front
- Config at `config/websockets.php`, apps defined via env vars

## Conventions
- Improvements go here (the package), not in consumer vendor/ folders
- Commit, push, then `composer update` in the consumer project
