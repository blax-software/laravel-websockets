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
        cache()->forget('ws_channel_connections');
        cache()->forget('ws_connection');

        return true;
    }

    public static function getAuth(string $socketId)
    {
        config(['cache.default' => 'file']);
        return cache()->get('ws_socket_auth_' . $socketId);
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
        return cache()->get('ws_connection_' . $socketId);
    }

    public static function getAuthedUsers()
    {
        config(['cache.default' => 'file']);
        return cache()->get('ws_socket_authed_users') ?? [];
    }

    public static function isUserConnected($userId)
    {
        config(['cache.default' => 'file']);
        $authed_users = cache()->get('ws_socket_authed_users') ?? [];
        $user_ids = array_values($authed_users);

        return in_array($userId, $user_ids);
    }

    public static function getUserSocketIds($userId)
    {
        config(['cache.default' => 'file']);
        $authed_users = cache()->get('ws_socket_authed_users') ?? [];
        $socket_ids = [];

        foreach ($authed_users as $socket_id => $u_id) {
            if ($u_id == $userId) {
                $socket_ids[] = $socket_id;
            }
        }

        return $socket_ids;
    }
}
