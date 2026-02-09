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
     *
     * Supports the legacy 'app.whisp' pattern where data contains:
     * - 'event': The actual event name to broadcast
     * - 'data': The actual data payload
     * - 'sockets': Target socket IDs (optional)
     * - 'channel': Target channel (optional)
     */
    public static function send(
        string $event,
        mixed $data,
        $channel = 'websocket'
    ) {
        // Try efficient broadcast socket first (Unix socket IPC)
        if (ws_available()) {
            // Handle legacy 'app.whisp' pattern - extract inner event, data, and sockets
            if ($event === 'app.whisp' && is_array($data)) {
                $innerEvent = $data['event'] ?? 'info:message';
                $innerData = $data['data'] ?? [];
                $innerChannel = $data['channel'] ?? $channel ?? 'websocket';
                $targetSockets = $data['sockets'] ?? null;

                \Log::info('[WebsocketService] Sending via broadcast socket (app.whisp)', [
                    'innerEvent' => $innerEvent,
                    'innerChannel' => $innerChannel,
                    'targetSockets' => $targetSockets ? count($targetSockets) : 'all',
                ]);

                if (!empty($targetSockets) && is_array($targetSockets)) {
                    // Whisper to specific sockets
                    $success = ws_whisper($innerEvent, $innerData, $targetSockets, $innerChannel);
                } else {
                    // Broadcast to all
                    $success = ws_broadcast($innerEvent, $innerData, $innerChannel);
                }

                if ($success) {
                    return (object)['success' => true, 'method' => 'broadcast_socket'];
                }
                \Log::warning('[WebsocketService] Broadcast socket failed, falling back to WebSocket');
                // Fall through to WebSocket client if broadcast socket fails
            } else {
                // Regular broadcast
                $success = ws_broadcast($event, is_array($data) ? $data : ['data' => $data], $channel ?? 'websocket');
                if ($success) {
                    return (object)['success' => true, 'method' => 'broadcast_socket'];
                }
                // Fall through to WebSocket client if broadcast socket fails
            }
        } else {
            \Log::info('[WebsocketService] Broadcast socket not available, using WebSocket fallback');
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
        try {
            $client = new \WebSocket\Client('ws://0.0.0.0:6001/app/' . config('websockets.apps.0.id'), [
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
        } catch (\Exception $e) {
            \Log::warning('[WebsocketService] sendViaWebSocket failed: ' . $e->getMessage());
            return (object)['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function resetAllTracking()
    {
        cache()->forget('ws_active_channels');
        cache()->forget('ws_socket_auth');
        cache()->forget('ws_socket_auth_users');
        cache()->forget('ws_socket_authed_users');
        cache()->forget('ws_channel_connections');
        cache()->forget('ws_connection');

        return true;
    }


    public static function getAuth(string $socketId)
    {
        return cache()->get('ws_socket_auth_' . str()->slug($socketId));
    }

    public static function getChannelConnections(string $channelName): array
    {
        return cache()->get('ws_channel_connections_' . $channelName) ?? [];
    }

    public static function getActiveChannels(): array
    {
        return cache()->get('ws_active_channels') ?? [];
    }

    public static function getConnection(string $socketId)
    {
        return cache()->get('ws_connection_' . str()->slug($socketId));
    }

    public static function getAuthedUsers(): array
    {
        return cache()->get('ws_socket_authed_users') ?? [];
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

        cache()->forever('ws_socket_authed_users', $authed_users);
        cache()->forever('ws_socket_auth_' . str()->slug($socketId), $user);

        return static::getAuthedUsers();
    }

    public static function clearUserAuthed($socketId)
    {
        $authed_users = static::getAuthedUsers();
        unset($authed_users[$socketId]);

        cache()->forever('ws_socket_authed_users', $authed_users);
        cache()->forget('ws_socket_auth_' . str()->slug($socketId));

        return static::getAuthedUsers();
    }
}
