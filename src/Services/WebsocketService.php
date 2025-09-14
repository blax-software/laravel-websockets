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

    function getTenantable(string $socketId)
    {
        config(['cache.default' => 'file']);
        return cache()->get('ws_socket_tenantable_' . $socketId);
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
}
