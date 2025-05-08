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
}
