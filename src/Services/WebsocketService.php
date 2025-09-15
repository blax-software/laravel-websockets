<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Services;

class WebsocketService
{
    public static function send(
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
        config(['cache.default' => 'file']);
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
        config(['cache.default' => 'file']);
        return cache()->get('ws_socket_auth_' . str()->slug($socketId));
    }

    public static function getChannelConnections(string $channelName)
    {
        config(['cache.default' => 'file']);
        return cache()->get('ws_channel_connections_' . $channelName);
    }

    public static function getActiveChannels()
    {
        config(['cache.default' => 'file']);
        return cache()->get('ws_active_channels');
    }

    public static function getConnection(string $socketId)
    {
        config(['cache.default' => 'file']);
        return cache()->get('ws_connection_' . str()->slug($socketId));
    }

    public static function getAuthedUsers()
    {
        config(['cache.default' => 'file']);
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
