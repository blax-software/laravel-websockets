<?php

/**
 * Global helper functions for WebSocket broadcasting.
 *
 * These functions provide an efficient way to broadcast messages
 * to WebSocket clients from anywhere in your Laravel application.
 */

use BlaxSoftware\LaravelWebSockets\Broadcast\BroadcastClient;

if (!function_exists('ws_broadcast')) {
    /**
     * Broadcast a message to all clients on a channel.
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param string $channel Channel name (default: 'websocket')
     * @return bool Success
     *
     * @example
     * // Broadcast to all clients on the default 'websocket' channel
     * ws_broadcast('notification', ['message' => 'Hello!']);
     *
     * // Broadcast to a specific channel
     * ws_broadcast('update', ['status' => 'complete'], 'private-user.123');
     */
    function ws_broadcast(string $event, array $data, string $channel = 'websocket'): bool
    {
        return BroadcastClient::instance()->send($event, $data, $channel);
    }
}

if (!function_exists('ws_whisper')) {
    /**
     * Send a message to specific socket IDs only.
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param array $sockets Target socket IDs
     * @param string $channel Channel name (default: 'websocket')
     * @return bool Success
     *
     * @example
     * // Send to specific sockets
     * ws_whisper('typing', ['user' => 'John'], ['socket-123', 'socket-456']);
     */
    function ws_whisper(string $event, array $data, array $sockets, string $channel = 'websocket'): bool
    {
        return BroadcastClient::instance()->whisper($event, $data, $sockets, $channel);
    }
}

if (!function_exists('ws_broadcast_except')) {
    /**
     * Broadcast a message to all clients except specified socket IDs.
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param array $excludeSockets Socket IDs to exclude
     * @param string $channel Channel name (default: 'websocket')
     * @return bool Success
     *
     * @example
     * // Broadcast to all except the sender
     * ws_broadcast_except('message', ['text' => 'Hi'], [$currentSocketId]);
     */
    function ws_broadcast_except(string $event, array $data, array $excludeSockets, string $channel = 'websocket'): bool
    {
        return BroadcastClient::instance()->broadcastExcept($event, $data, $excludeSockets, $channel);
    }
}

if (!function_exists('ws_client')) {
    /**
     * Get the WebSocket broadcast client instance.
     *
     * @return BroadcastClient
     *
     * @example
     * // Check if WebSocket server is available
     * if (ws_client()->isAvailable()) {
     *     ws_broadcast('event', $data);
     * }
     */
    function ws_client(): BroadcastClient
    {
        return BroadcastClient::instance();
    }
}

if (!function_exists('ws_available')) {
    /**
     * Check if the WebSocket broadcast server is available.
     *
     * @return bool
     */
    function ws_available(): bool
    {
        return BroadcastClient::instance()->isAvailable();
    }
}
