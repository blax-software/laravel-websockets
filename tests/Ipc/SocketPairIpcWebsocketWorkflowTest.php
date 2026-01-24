<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Ipc;

use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use BlaxSoftware\LaravelWebSockets\Websocket\MockConnectionSocketPair;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use React\EventLoop\Factory as LoopFactory;

/**
 * Tests simulating real WebSocket workflows using SocketPairIpc.
 *
 * These tests verify the IPC handles typical WebSocket message patterns:
 * - Connection establishment
 * - Channel subscription
 * - Message broadcasting
 * - Whispers (client-to-client)
 * - Connection close
 * - Error handling
 */
class SocketPairIpcWebsocketWorkflowTest extends TestCase
{
    /**
     * Simulate a connection_established event
     */
    public function test_connection_established_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate what happens when a client connects
            $connectionEstablished = json_encode([
                'event' => 'pusher:connection_established',
                'data' => json_encode([
                    'socket_id' => '123.456',
                    'activity_timeout' => 30,
                ]),
            ]);

            $ipc->sendToParent($connectionEstablished);
            $ipc->closeChild();
            exit(0);
        }

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

        $this->assertNotNull($receivedData);

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher:connection_established', $decoded['event']);

        $data = json_decode($decoded['data'], true);
        $this->assertEquals('123.456', $data['socket_id']);
        $this->assertEquals(30, $data['activity_timeout']);
    }

    /**
     * Simulate channel subscription workflow
     */
    public function test_channel_subscription_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate subscription success response
            $subscriptionSuccess = json_encode([
                'event' => 'pusher_internal:subscription_succeeded',
                'channel' => 'public-channel',
                'data' => '{}',
            ]);

            $ipc->sendToParent($subscriptionSuccess);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher_internal:subscription_succeeded', $decoded['event']);
        $this->assertEquals('public-channel', $decoded['channel']);
    }

    /**
     * Simulate presence channel subscription with member data
     */
    public function test_presence_channel_subscription_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate presence channel subscription with member data
            $presenceData = [
                'presence' => [
                    'ids' => ['user_1', 'user_2', 'user_3'],
                    'hash' => [
                        'user_1' => ['name' => 'Alice'],
                        'user_2' => ['name' => 'Bob'],
                        'user_3' => ['name' => 'Charlie'],
                    ],
                    'count' => 3,
                ],
            ];

            $subscriptionSuccess = json_encode([
                'event' => 'pusher_internal:subscription_succeeded',
                'channel' => 'presence-room.1',
                'data' => json_encode($presenceData),
            ]);

            $ipc->sendToParent($subscriptionSuccess);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher_internal:subscription_succeeded', $decoded['event']);
        $this->assertEquals('presence-room.1', $decoded['channel']);

        $data = json_decode($decoded['data'], true);
        $this->assertEquals(3, $data['presence']['count']);
        $this->assertCount(3, $data['presence']['ids']);
    }

    /**
     * Simulate broadcast message workflow
     */
    public function test_broadcast_message_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate a broadcast message (internal format)
            $broadcastMessage = json_encode([
                'broadcast' => true,
                'event' => 'message.sent',
                'channel' => 'chat-room.1',
                'data' => [
                    'message' => 'Hello, World!',
                    'user_id' => 1,
                    'timestamp' => time(),
                ],
                'including_self' => false,
            ]);

            $ipc->sendToParent($broadcastMessage);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertTrue($decoded['broadcast']);
        $this->assertEquals('message.sent', $decoded['event']);
        $this->assertEquals('chat-room.1', $decoded['channel']);
        $this->assertEquals('Hello, World!', $decoded['data']['message']);
    }

    /**
     * Simulate whisper (client-to-client) message workflow
     */
    public function test_whisper_message_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate a whisper message
            $whisperMessage = json_encode([
                'whisper' => true,
                'event' => 'client-typing',
                'channel' => 'presence-room.1',
                'socket_ids' => ['789.012', '345.678'],
                'data' => [
                    'user' => 'Alice',
                    'typing' => true,
                ],
            ]);

            $ipc->sendToParent($whisperMessage);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertTrue($decoded['whisper']);
        $this->assertEquals('client-typing', $decoded['event']);
        $this->assertCount(2, $decoded['socket_ids']);
    }

    /**
     * Simulate error response workflow
     */
    public function test_error_response_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate an error response
            $errorResponse = json_encode([
                'event' => 'pusher:error',
                'data' => [
                    'message' => 'Could not find app key `InvalidKey`.',
                    'code' => 4001,
                ],
            ]);

            $ipc->sendToParent($errorResponse);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher:error', $decoded['event']);
        $this->assertEquals(4001, $decoded['data']['code']);
    }

    /**
     * Simulate member_added event for presence channels
     */
    public function test_member_added_event_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            $memberAdded = json_encode([
                'event' => 'pusher_internal:member_added',
                'channel' => 'presence-room.1',
                'data' => json_encode([
                    'user_id' => 'user_4',
                    'user_info' => ['name' => 'Dave'],
                ]),
            ]);

            $ipc->sendToParent($memberAdded);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher_internal:member_added', $decoded['event']);

        $data = json_decode($decoded['data'], true);
        $this->assertEquals('user_4', $data['user_id']);
    }

    /**
     * Simulate member_removed event for presence channels
     */
    public function test_member_removed_event_workflow()
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

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            $memberRemoved = json_encode([
                'event' => 'pusher_internal:member_removed',
                'channel' => 'presence-room.1',
                'data' => json_encode([
                    'user_id' => 'user_2',
                ]),
            ]);

            $ipc->sendToParent($memberRemoved);
            $ipc->closeChild();
            exit(0);
        }

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

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher_internal:member_removed', $decoded['event']);
    }

    /**
     * Simulate ping/pong workflow
     */
    public function test_ping_pong_workflow()
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
        $startTime = microtime(true);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // Simulate pong response
            $pongResponse = json_encode([
                'event' => 'pusher:pong',
                'data' => '{}',
            ]);

            $ipc->sendToParent($pongResponse);
            $ipc->closeChild();
            exit(0);
        }

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

        $latency = (microtime(true) - $startTime) * 1000;

        pcntl_waitpid($pid, $status);

        $decoded = json_decode($receivedData, true);
        $this->assertEquals('pusher:pong', $decoded['event']);

        // Ping/pong should be very fast
        $this->assertLessThan(50, $latency, "Ping/pong latency {$latency}ms exceeds 50ms");
    }

    /**
     * Simulate full connection lifecycle
     */
    public function test_full_connection_lifecycle()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedMessages = [];

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            // 1. Connection established
            $ipc->sendToParent(json_encode([
                'event' => 'pusher:connection_established',
                'data' => json_encode(['socket_id' => '123.456', 'activity_timeout' => 30]),
            ]));

            // 2. Subscribe to channel
            $ipc->sendToParent(json_encode([
                'event' => 'pusher_internal:subscription_succeeded',
                'channel' => 'public-chat',
                'data' => '{}',
            ]));

            // 3. Receive a message
            $ipc->sendToParent(json_encode([
                'event' => 'new-message',
                'channel' => 'public-chat',
                'data' => json_encode(['text' => 'Hello!']),
            ]));

            // 4. Ping response
            $ipc->sendToParent(json_encode([
                'event' => 'pusher:pong',
                'data' => '{}',
            ]));

            // 5. Unsubscribe
            $ipc->sendToParent(json_encode([
                'event' => 'pusher_internal:unsubscribed',
                'channel' => 'public-chat',
            ]));

            $ipc->closeChild();
            exit(0);
        }

        $expectedCount = 5;
        $ipc->setupParent(
            function ($data) use (&$receivedMessages, $loop, $expectedCount) {
                $receivedMessages[] = json_decode($data, true);
                if (count($receivedMessages) >= $expectedCount) {
                    $loop->stop();
                }
            },
            function () {}
        );

        $loop->addTimer(5.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        $this->assertCount(5, $receivedMessages);

        // Verify lifecycle order
        $this->assertEquals('pusher:connection_established', $receivedMessages[0]['event']);
        $this->assertEquals('pusher_internal:subscription_succeeded', $receivedMessages[1]['event']);
        $this->assertEquals('new-message', $receivedMessages[2]['event']);
        $this->assertEquals('pusher:pong', $receivedMessages[3]['event']);
        $this->assertEquals('pusher_internal:unsubscribed', $receivedMessages[4]['event']);
    }

    /**
     * Test MockConnectionSocketPair with WebSocket-like workflow
     */
    public function test_mock_connection_websocket_workflow()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $receivedMessages = [];

        // Create mock real connection
        $realConnection = new class implements ConnectionInterface {
            public string $socketId = '123.456';
            public ?object $app = null;

            public function __construct()
            {
                $this->app = (object) ['id' => '1234', 'key' => 'TestKey'];
            }

            public function send($data) {}
            public function close() {}
        };

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            $mock = new MockConnectionSocketPair($realConnection, $ipc);

            // Simulate sending multiple messages through mock
            $mock->send(json_encode(['event' => 'pusher:connection_established', 'data' => '{}']));
            $mock->send(json_encode(['event' => 'pusher_internal:subscription_succeeded', 'channel' => 'test']));
            $mock->send(json_encode(['event' => 'message', 'data' => 'Hello']));

            $ipc->closeChild();
            exit(0);
        }

        $expectedCount = 3;
        $ipc->setupParent(
            function ($data) use (&$receivedMessages, $loop, $expectedCount) {
                $receivedMessages[] = json_decode($data, true);
                if (count($receivedMessages) >= $expectedCount) {
                    $loop->stop();
                }
            },
            function () {}
        );

        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        $this->assertCount(3, $receivedMessages);
        $this->assertEquals('pusher:connection_established', $receivedMessages[0]['event']);
        $this->assertEquals('pusher_internal:subscription_succeeded', $receivedMessages[1]['event']);
        $this->assertEquals('message', $receivedMessages[2]['event']);
    }
}
