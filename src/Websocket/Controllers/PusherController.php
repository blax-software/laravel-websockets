<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket\Controllers;

class PusherController extends \BlaxSoftware\LaravelWebSockets\Websocket\Controller
{
    public $need_auth = false;

    public function unsubscribe($connection, $data, $channel)
    {
        // $this->broadcast(
        //     $this->get_users_in_channel(),
        //     'channel:left',
        //     including_self: true
        // );

        return $this->success([], 'channel:joined');
    }

    public function subscribe($connection, $data, $channel)
    {
        // $this->broadcast(
        //     $this->get_users_in_channel(),
        //     'channel:joined',
        //     including_self: true
        // );

        return $this->success([], 'channel:joined');
    }
}
