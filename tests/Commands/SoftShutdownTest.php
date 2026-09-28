<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Commands;

use BlaxSoftware\LaravelWebSockets\Console\Commands\StartServer;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Helpers;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;
use Illuminate\Console\OutputStyle;
use React\EventLoop\Factory as LoopFactory;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The soft shutdown must always stop the event loop on its own. It used to chain
 * ->then() on Handler::onClose() (which returns void): the error was dropped as
 * a promise rejection, the loop never stopped, and the server kept declining
 * every new connection until a hard restart. Driven on a fresh loop with fake
 * connections so nothing else can stop the loop for it.
 */
class SoftShutdownTest extends TestCase
{
    private function runSoftShutdown(object $channelManager): array
    {
        $loop = LoopFactory::create();
        $this->app->instance(ChannelManager::class, $channelManager);

        $command = $this->app->make(StartServer::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new \ReflectionProperty(StartServer::class, 'loop'))->setValue($command, $loop);

        $stoppedBySafetyNet = false;
        $loop->addTimer(8, function () use (&$stoppedBySafetyNet, $loop) {
            $stoppedBySafetyNet = true;
            $loop->stop();
        });

        $trigger = new \ReflectionMethod(StartServer::class, 'triggerSoftShutdown');
        $loop->futureTick(fn () => $trigger->invoke($command));

        $started = microtime(true);
        $loop->run();

        return [$stoppedBySafetyNet, microtime(true) - $started];
    }

    private function fakeConnection(): object
    {
        return new class {
            public bool $closed = false;

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }

    public function test_soft_shutdown_closes_connections_and_stops_the_loop()
    {
        $connections = [$this->fakeConnection(), $this->fakeConnection()];
        $manager = new class($connections) {
            public bool $declined = false;

            public function __construct(private array $connections) {}

            public function declineNewConnections(): void
            {
                $this->declined = true;
            }

            public function getLocalConnections(): PromiseInterface
            {
                return Helpers::createFulfilledPromise($this->connections);
            }
        };

        [$stoppedBySafetyNet, $seconds] = $this->runSoftShutdown($manager);

        $this->assertFalse($stoppedBySafetyNet, 'The soft shutdown never stopped the event loop.');
        $this->assertTrue($manager->declined);
        $this->assertTrue($connections[0]->closed && $connections[1]->closed, 'Every connection is closed.');
        // The loop waits the grace period so Ratchet can run onClose for each connection.
        $this->assertGreaterThanOrEqual(0.9, $seconds);
    }

    public function test_soft_shutdown_stops_the_loop_even_when_listing_connections_fails()
    {
        $manager = new class {
            public function declineNewConnections(): void {}

            public function getLocalConnections(): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('redis unavailable'));
            }
        };

        [$stoppedBySafetyNet] = $this->runSoftShutdown($manager);

        $this->assertFalse($stoppedBySafetyNet, 'A failed connection listing left the loop running.');
    }
}
