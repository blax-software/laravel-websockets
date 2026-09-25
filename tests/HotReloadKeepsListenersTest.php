<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

use BlaxSoftware\LaravelWebSockets\Websocket\Handler;
use Illuminate\Support\Facades\Event;

/**
 * Regression guard: the dev hot reload in the message child used to forget the
 * `events` instance. The container then built an empty dispatcher, and every
 * listener the service providers had registered (Event::listen in boot()) was
 * gone for that message, so events fired from a WS controller reached nobody
 * (e.g. a shop's "order paid" mails and invoice never happened in dev).
 */
class HotReloadKeepsListenersTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setHotReload(null);

        parent::tearDown();
    }

    private function setHotReload(?bool $on): void
    {
        $flag = new \ReflectionProperty(Handler::class, 'hotReload');
        $flag->setAccessible(true);
        $flag->setValue(null, $on);
    }

    public function test_listeners_registered_at_boot_survive_the_hot_reload()
    {
        $heard = 0;
        Event::listen('shop.order-paid', function () use (&$heard) {
            $heard++;
        });

        $this->setHotReload(true);
        $reload = new \ReflectionMethod($this->wsHandler, 'hotReloadChild');
        $reload->setAccessible(true);
        $reload->invoke($this->wsHandler);

        event('shop.order-paid');

        $this->assertSame(1, $heard);
    }
}
