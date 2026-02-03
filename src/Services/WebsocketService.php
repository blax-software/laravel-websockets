<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Services;

use BlaxSoftware\LaravelWebSockets\Broadcast\BroadcastClient;

class WebsocketService
{
    /**
     * Send a message via WebSocket.
     *
     * Automatically uses the efficient Unix socket broadcast when available,
     * falling back to creating a new WebSocket connection when not.
     */
    public static function send(
        string $event,
        mixed $data,
        $channel = 'websocket'
    ) {
        // Try efficient broadcast socket first (Unix socket IPC)
        if (ws_available()) {
            $success = ws_broadcast($event, is_array($data) ? $data : ['data' => $data], $channel ?? 'websocket');
            if ($success) {
                return (object)['success' => true, 'method' => 'broadcast_socket'];
            }
            // Fall through to WebSocket client if broadcast socket fails
        }

        // Fallback: Create new WebSocket connection (slower, for when broadcast socket not available)
        return static::sendViaWebSocket($event, $data, $channel);
    }

    /**
     * Send a message to specific socket IDs only.
     *
     * @param string $event Event name
     * @param mixed $data Event data
     * @param array $sockets Target socket IDs
     * @param string $channel Channel name
     * @return bool Success
     */
    public static function whisper(
        string $event,
        mixed $data,
        array $sockets,
        string $channel = 'websocket'
    ): bool {
        if (!ws_available()) {
            return false;
        }

        return ws_whisper($event, is_array($data) ? $data : ['data' => $data], $sockets, $channel);
    }

    /**
     * Broadcast to all except specified socket IDs.
     *
     * @param string $event Event name
     * @param mixed $data Event data
     * @param array $excludeSockets Socket IDs to exclude
     * @param string $channel Channel name
     * @return bool Success
     */
    public static function broadcastExcept(
        string $event,
        mixed $data,
        array $excludeSockets,
        string $channel = 'websocket'
    ): bool {
        if (!ws_available()) {
            return false;
        }

        return ws_broadcast_except($event, is_array($data) ? $data : ['data' => $data], $excludeSockets, $channel);
    }

    /**
     * Send a message by creating a new WebSocket connection.
     * This is the legacy method, kept for fallback when broadcast socket is unavailable.
     */
    protected static function sendViaWebSocket(
        string $event,
        mixed $data,
        $channel = 'websocket'
    ) {
        $client = new \WebSocket\Client('ws://0.0.0.0:6001/app/'.config('websockets.apps.0.id'), [
            'timeout' => 5,
            'headers' => [],
        ]);

        // Read connection_established
        $client->receive();

        // Subscribe (public channel)
        $client->send(json_encode([
            'event' => 'pusher:subscribe',
            'data'  => ['channel' => 'websocket'],
        ]));

        // (Optionally read subscription_succeeded)
        $client->receive();

        // Send event to be processed by Handler
        $client->send(json_encode([
            'event' => $event,
            'channel' => $channel ?? 'websocket',
            'data' => $data,
        ]));

        // Read any response your controller might send (optional)
        $response = $client->receive();

        $client->close();

        return json_decode($response);
    }

    public static function resetAllTracking()
    {
        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        cache()->forget('ws_active_channels');
        cache()->forget('ws_socket_auth');
        cache()->forget('ws_socket_auth_users');
        cache()->forget('ws_socket_authed_users');
        cache()->forget('ws_channel_connections');
        cache()->forget('ws_connection');
        config(['cache.default' => $previousCache]);

        return true;
    }


    public static function getAuth(string $socketId)
    {
        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        $r = cache()->get('ws_socket_auth_' . str()->slug($socketId));
        config(['cache.default' => $previousCache]);
        return $r;
    }

    public static function getChannelConnections(string $channelName)
    {
        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        $r = cache()->get('ws_channel_connections_' . $channelName);
        config(['cache.default' => $previousCache]);
        return $r;
    }

    public static function getActiveChannels()
    {
        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        $r = cache()->get('ws_active_channels');
        config(['cache.default' => $previousCache]);
        return $r;
    }

    public static function getConnection(string $socketId)
    {
        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        $r = cache()->get('ws_connection_' . str()->slug($socketId));
        config(['cache.default' => $previousCache]);
        return $r;
    }

    public static function getAuthedUsers()
    {
        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        $r = cache()->get('ws_socket_authed_users') ?? [];
        config(['cache.default' => $previousCache]);
        return $r;
    }

    public static function isUserConnected($userId)
    {
        return in_array($userId, array_values(static::getAuthedUsers()));
    }

    public static function getUserSocketIds($userId)
    {
        $socket_ids = [];

        foreach (static::getAuthedUsers() as $socket_id => $u_id) {
            if ($u_id == $userId) {
                $socket_ids[] = $socket_id;
            }
        }

        return $socket_ids;
    }

    public static function setUserAuthed($socketId, $user)
    {
        $authed_users = static::getAuthedUsers();
        $authed_users[$socketId] = $user->id;

        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        cache()->forever('ws_socket_authed_users', $authed_users);
        cache()->forever('ws_socket_auth_' . str()->slug($socketId), $user);
        config(['cache.default' => $previousCache]);

        return static::getAuthedUsers();
    }

    public static function clearUserAuthed($socketId)
    {
        $authed_users = static::getAuthedUsers();
        unset($authed_users[$socketId]);

        $previousCache = config('cache.default');
        config(['cache.default' => 'file']);
        cache()->forever('ws_socket_authed_users', $authed_users);
        cache()->forget('ws_socket_auth_' . str()->slug($socketId));
        config(['cache.default' => $previousCache]);

        return static::getAuthedUsers();
    }
}
