<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

class PingTest extends TestCase
{
    public function test_ping_returns_pong()
    {
        $connection = $this->newActiveConnection(['public-channel']);

        $message = new Mocks\Message(['event' => 'websocket.ping']);

        $this->wsHandler->onMessage($connection, $message);

        $connection->assertSentEvent('websocket.pong');
    }
}
