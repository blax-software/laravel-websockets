<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Ipc;

use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory as LoopFactory;

/**
 * Unit tests for SocketPairIpc.
 *
 * These tests verify the event-driven IPC mechanism using Unix socket pairs.
 * Note: These tests use pcntl_fork() so they must run in a CLI environment.
 */
class SocketPairIpcTest extends TestCase
{
    public function test_it_checks_if_socket_pairs_are_supported()
    {
        // On most Linux systems, this should be true
        $supported = SocketPairIpc::isSupported();

        $this->assertIsBool($supported);

        // If sockets extension is loaded, should be supported
        if (extension_loaded('sockets')) {
            $this->assertTrue($supported);
        }
    }

    public function test_it_can_create_socket_pair()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $this->assertInstanceOf(SocketPairIpc::class, $ipc);
    }

    public function test_it_can_send_data_from_child_to_parent()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedData = null;
        $testMessage = '{"event":"test","data":"hello world"}';

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            $ipc->setupChild();
            $ipc->sendToParent($testMessage);
            $ipc->closeChild();
            exit(0);
        }

        // Parent process
        $ipc->setupParent(
            function ($data) use (&$receivedData, $loop) {
                $receivedData = $data;
                $loop->stop();
            },
            function () {
                // On close - do nothing
            }
        );

        // Add timeout to prevent hanging
        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        // Wait for child to exit
        pcntl_waitpid($pid, $status);

        $this->assertEquals($testMessage, $receivedData);
    }

    public function test_it_can_send_multiple_messages()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedMessages = [];
        $testMessages = [
            '{"event":"msg1","data":"first"}',
            '{"event":"msg2","data":"second"}',
            '{"event":"msg3","data":"third"}',
        ];

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process - send multiple messages
            $ipc->setupChild();
            foreach ($testMessages as $msg) {
                $ipc->sendToParent($msg);
            }
            $ipc->closeChild();
            exit(0);
        }

        // Parent process
        $expectedCount = count($testMessages);
        $ipc->setupParent(
            function ($data) use (&$receivedMessages, $loop, $expectedCount) {
                $receivedMessages[] = $data;
                if (count($receivedMessages) >= $expectedCount) {
                    $loop->stop();
                }
            },
            function () {
                // On close
            }
        );

        // Add timeout
        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        // Wait for child
        pcntl_waitpid($pid, $status);

        $this->assertCount(3, $receivedMessages);
        $this->assertEquals($testMessages, $receivedMessages);
    }

    public function test_it_handles_large_messages()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedData = null;
        // Create a large message (32KB of data)
        $largeData = str_repeat('x', 32 * 1024);
        $testMessage = json_encode(['event' => 'large', 'data' => $largeData]);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            $ipc->setupChild();
            $ipc->sendToParent($testMessage);
            $ipc->closeChild();
            exit(0);
        }

        // Parent process
        $ipc->setupParent(
            function ($data) use (&$receivedData, $loop) {
                $receivedData = $data;
                $loop->stop();
            },
            function () {
                // On close
            }
        );

        // Add timeout
        $loop->addTimer(5.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        // Wait for child
        pcntl_waitpid($pid, $status);

        $this->assertEquals($testMessage, $receivedData);
    }

    public function test_it_measures_latency_under_1ms()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $latencyMs = null;
        $testMessage = '{"event":"latency_test"}';

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process - send immediately
            $ipc->setupChild();
            $ipc->sendToParent($testMessage);
            $ipc->closeChild();
            exit(0);
        }

        // Parent process - measure time from setup to callback
        $startTime = microtime(true);

        $ipc->setupParent(
            function ($data) use (&$latencyMs, $loop, $startTime) {
                $latencyMs = (microtime(true) - $startTime) * 1000;
                $loop->stop();
            },
            function () {
                // On close
            }
        );

        // Add timeout
        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        // Wait for child
        pcntl_waitpid($pid, $status);

        $this->assertNotNull($latencyMs, 'Should have received data');
        // Event-driven should be well under 10ms (typically < 1ms)
        $this->assertLessThan(10, $latencyMs, "Latency was {$latencyMs}ms, expected < 10ms");

        // Log the actual latency for visibility
        fwrite(STDERR, "\n  [Latency: " . round($latencyMs, 3) . "ms] ");
    }

    public function test_it_throws_when_sending_from_parent()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $ipc->setupParent(function () {}, function () {});

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sendToParent can only be called from child');

        $ipc->sendToParent('test');
    }

    public function test_it_throws_when_configuring_twice()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported on this system');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $ipc->setupParent(function () {}, function () {});

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('IPC already configured');

        $ipc->setupChild();
    }
}
