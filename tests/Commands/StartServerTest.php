<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Commands;

use BlaxSoftware\LaravelWebSockets\Test\TestCase;

class StartServerTest extends TestCase
{
    public function test_does_not_fail_if_building_up()
    {
        $this->loop->futureTick(function () {
            $this->loop->stop();
        });

        $this->artisan('websockets:serve', ['--loop' => $this->loop, '--debug' => true, '--port' => 6001]);

        $this->assertTrue(true);
    }

    public function test_pcntl_sigint_signal()
    {
        $this->loop->futureTick(function () {
            $this->newActiveConnection(['public-channel']);
            $this->newActiveConnection(['public-channel']);

            posix_kill(posix_getpid(), SIGINT);

            $this->loop->stop();
        });

        $this->artisan('websockets:serve', ['--loop' => $this->loop, '--debug' => true, '--port' => 6002]);

        $this->assertTrue(true);
    }

    public function test_pcntl_sigterm_signal()
    {
        $this->loop->futureTick(function () {
            $this->newActiveConnection(['public-channel']);
            $this->newActiveConnection(['public-channel']);

            posix_kill(posix_getpid(), SIGTERM);

            $this->loop->stop();
        });

        $this->artisan('websockets:serve', ['--loop' => $this->loop, '--debug' => true, '--port' => 6003]);

        $this->assertTrue(true);
    }

    public function test_soft_shutdown_stops_the_loop_on_its_own()
    {
        // The soft shutdown chained ->then() on Handler::onClose(), which returns
        // void. The resulting error became a silently dropped promise rejection,
        // loop->stop() never ran, and the server kept accepting and closing every
        // new socket until a hard restart. Unlike the tests above, nothing here
        // stops the loop for the server.
        $stoppedBySafetyNet = false;
        $errors = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$errors) {
            if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $errors[] = $event->message . ' ' . (($event->context['exception'] ?? null)?->getMessage() ?? '');
            }
        });

        $this->loop->futureTick(function () {
            // The server rebinds the channel manager on boot, so connect through
            // its own handler; connections made via $this->wsHandler would live
            // in the test's manager and the shutdown would see none.
            $handler = app('websockets.handler');
            for ($i = 0; $i < 2; $i++) {
                $connection = $this->newConnection();
                $handler->onOpen($connection);
                $handler->onMessage($connection, new \BlaxSoftware\LaravelWebSockets\Test\Mocks\Message([
                    'event' => 'websocket.subscribe',
                    'data' => ['channel' => 'public-channel'],
                ]));
            }

            posix_kill(posix_getpid(), SIGTERM);
        });

        $this->loop->addTimer(8, function () use (&$stoppedBySafetyNet) {
            $stoppedBySafetyNet = true;
            $this->loop->stop();
        });

        $this->artisan('websockets:serve', ['--loop' => $this->loop, '--debug' => true, '--port' => 6004, '--soft' => true]);

        $this->assertSame([], $errors, 'The soft shutdown logged errors.');
        $this->assertFalse($stoppedBySafetyNet, 'The soft shutdown never stopped the event loop.');
    }
}
