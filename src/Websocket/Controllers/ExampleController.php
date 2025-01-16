<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket\Controllers;

class ExampleController extends \BlaxSoftware\LaravelWebSockets\Websocket\Controller
{
    public $need_auth = true;

    public function index($connection, $data, $channel)
    {
        $user = auth()->user();

        return [
            'data' => [
                'Hi mom!'
            ],
            'meta' => [
                'user' => $user
            ],
        ];
    }
}
