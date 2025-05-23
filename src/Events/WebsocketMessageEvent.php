<?php

namespace BlaxSoftware\LaravelWebSockets\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;


class WebsocketMessageEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $event,
        public $data
    ) {}

    public function broadcastOn()
    {
        return [
            new PrivateChannel('websocket'),
        ];
    }

    public function broadcastWith()
    {
        return $this->data;
    }

    public function broadcastAs()
    {
        return $this->event;
    }
}
