<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Services;

use BlaxSoftware\LaravelWebSockets\Events\WebsocketMessageEvent;

class WebsocketService
{
    public static function send($data)
    {
        // TODO make work to send via websocket from anywhere
        // WebsocketMessageEvent::dispatch(
        //     optional(optional(tenant())->tenantable)->public_id,
        //     $d['event'],
        //     (is_array($d['data']))
        //         ? $d['data']
        //         : ['data' => $d['data']]
        // );
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
