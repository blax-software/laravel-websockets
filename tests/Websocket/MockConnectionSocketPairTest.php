<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Websocket;

use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use BlaxSoftware\LaravelWebSockets\Websocket\MockConnectionSocketPair;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use React\EventLoop\Factory as LoopFactory;

/**
 * Unit tests for MockConnectionSocketPair.
 */
class MockConnectionSocketPairTest extends TestCase
{
    public function test_it_sends_data_through_socket_pair()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedData = null;
        $testMessage = '{"event":"test","data":"value"}';

        // Create a mock real connection
        $realConnection = $this->createMock(ConnectionInterface::class);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            $ipc->setupChild();

            $mock = new MockConnectionSocketPair($realConnection, $ipc);
            $mock->send($testMessage);

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

        // Timeout
        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        $this->assertEquals($testMessage, $receivedData);
    }

    public function test_it_strips_newlines_from_data()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedData = null;
        // Message with embedded newlines
        $testMessage = "{\"event\":\"test\",\n\"data\":\"line1\nline2\r\nline3\"}";
        $expectedMessage = "{\"event\":\"test\", \"data\":\"line1 line2 line3\"}";

        $realConnection = $this->createMock(ConnectionInterface::class);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            $ipc->setupChild();

            $mock = new MockConnectionSocketPair($realConnection, $ipc);
            $mock->send($testMessage);

            $ipc->closeChild();
            exit(0);
        }

        // Parent process
        $ipc->setupParent(
            function ($data) use (&$receivedData, $loop) {
                $receivedData = $data;
                $loop->stop();
            },
            function () {}
        );

        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        // Should not contain newlines
        $this->assertStringNotContainsString("\n", $receivedData);
        $this->assertStringNotContainsString("\r", $receivedData);
    }

    public function test_it_converts_arrays_to_json()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedData = null;
        $testArray = ['event' => 'test', 'data' => ['key' => 'value']];

        $realConnection = $this->createMock(ConnectionInterface::class);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            $ipc->setupChild();

            $mock = new MockConnectionSocketPair($realConnection, $ipc);
            // Send array instead of string
            $mock->send($testArray);

            $ipc->closeChild();
            exit(0);
        }

        // Parent process
        $ipc->setupParent(
            function ($data) use (&$receivedData, $loop) {
                $receivedData = $data;
                $loop->stop();
            },
            function () {}
        );

        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        // Should be valid JSON
        $decoded = json_decode($receivedData, true);
        $this->assertNotNull($decoded);
        $this->assertEquals($testArray, $decoded);
    }

    public function test_it_proxies_properties_from_real_connection()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        // Create a real connection mock with properties
        $realConnection = new class implements ConnectionInterface {
            public string $socketId = '123.456';
            public ?object $app = null;

            public function __construct()
            {
                $this->app = (object) ['id' => 'test-app', 'key' => 'test-key'];
            }

            public function send($data) {}
            public function close() {}
        };

        // Don't fork - just test the proxy locally
        $ipc->setupChild();
        $mock = new MockConnectionSocketPair($realConnection, $ipc);

        // Test property access
        $this->assertEquals('123.456', $mock->socketId);
        $this->assertEquals('test-app', $mock->app->id);
        $this->assertEquals('test-key', $mock->app->key);
    }

    public function test_it_returns_self_from_send()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $realConnection = $this->createMock(ConnectionInterface::class);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child process
            $ipc->setupChild();
            $mock = new MockConnectionSocketPair($realConnection, $ipc);

            // Test that send returns self for fluent interface
            $result = $mock->send('test');

            // Exit with 0 if send returned self, 1 otherwise
            exit($result === $mock ? 0 : 1);
        }

        // Parent process - setup to receive
        $ipc->setupParent(function ($data) use ($loop) {
            $loop->stop();
        }, function () {});

        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        // Child exit code 0 means send() returned $this
        $this->assertEquals(0, pcntl_wexitstatus($status));
    }
}
