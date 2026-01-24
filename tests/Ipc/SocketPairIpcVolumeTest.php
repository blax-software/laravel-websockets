<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Ipc;

use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory as LoopFactory;

/**
 * High-volume and performance tests for SocketPairIpc.
 *
 * These tests verify that the event-driven IPC can handle
 * realistic WebSocket workloads with many concurrent messages.
 */
class SocketPairIpcVolumeTest extends TestCase
{
    /**
     * Test sending 1000 messages in under 2 seconds.
     * This simulates a high-traffic WebSocket server.
     */
    public function test_it_can_handle_1000_messages_in_under_2_seconds()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $messageCount = 1000;
        $receivedMessages = [];
        $startTime = microtime(true);

        // We'll fork multiple times, each child sends messages
        $batchSize = 100;
        $batches = $messageCount / $batchSize;

        for ($batch = 0; $batch < $batches; $batch++) {
            $loop = LoopFactory::create();
            $ipc = SocketPairIpc::create($loop);

            $batchReceived = [];

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Fork failed');
            }

            if ($pid === 0) {
                // Child process - send batch of messages
                $ipc->setupChild();

                for ($i = 0; $i < $batchSize; $i++) {
                    $msgNum = ($batch * $batchSize) + $i;
                    $ipc->sendToParent(json_encode([
                        'event' => 'test_event',
                        'data' => ['message_number' => $msgNum],
                    ]));
                }

                $ipc->closeChild();
                exit(0);
            }

            // Parent process - receive messages
            $ipc->setupParent(
                function ($data) use (&$batchReceived, $loop, $batchSize) {
                    $batchReceived[] = $data;
                    if (count($batchReceived) >= $batchSize) {
                        $loop->stop();
                    }
                },
                function () use ($loop) {
                    $loop->stop();
                }
            );

            // Timeout after 2 seconds per batch
            $loop->addTimer(2.0, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            pcntl_waitpid($pid, $status);

            $receivedMessages = array_merge($receivedMessages, $batchReceived);
        }

        $totalTime = microtime(true) - $startTime;

        // Assert we received all messages
        $this->assertCount($messageCount, $receivedMessages, "Expected {$messageCount} messages, got " . count($receivedMessages));

        // Assert it took less than 2 seconds total
        $this->assertLessThan(2.0, $totalTime, "Expected < 2s, took {$totalTime}s");

        // Log performance metrics
        $messagesPerSecond = $messageCount / $totalTime;
        $avgLatencyMs = ($totalTime / $messageCount) * 1000;
        fwrite(STDERR, "\n  [1000 msgs in " . round($totalTime, 3) . "s = " . round($messagesPerSecond) . " msg/s, avg " . round($avgLatencyMs, 3) . "ms/msg] ");
    }

    /**
     * Test sending large JSON payloads (simulating real WebSocket data)
     */
    public function test_it_can_handle_large_json_payloads()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        // Create a large JSON payload similar to WebSocket broadcast
        $largePayload = json_encode([
            'event' => 'broadcast',
            'channel' => 'presence-room.1234',
            'data' => [
                'users' => array_map(function ($i) {
                    return [
                        'id' => $i,
                        'name' => 'User ' . $i,
                        'email' => "user{$i}@example.com",
                        'avatar' => 'https://example.com/avatar/' . $i . '.png',
                        'status' => 'online',
                        'metadata' => str_repeat('x', 100),
                    ];
                }, range(1, 50)),
                'room' => [
                    'id' => 1234,
                    'name' => 'Test Room',
                    'created_at' => date('c'),
                ],
            ],
        ]);

        $payloadSize = strlen($largePayload);
        $receivedData = null;

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();
            $ipc->sendToParent($largePayload);
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

        $loop->addTimer(5.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        $this->assertEquals($largePayload, $receivedData);
        fwrite(STDERR, "\n  [Large payload: " . round($payloadSize / 1024, 2) . "KB] ");
    }

    /**
     * Test rapid sequential messages (burst traffic)
     */
    public function test_it_handles_burst_traffic()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $burstCount = 50;
        $receivedMessages = [];

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            // Child - send rapid burst with no delay
            $ipc->setupChild();

            for ($i = 0; $i < $burstCount; $i++) {
                $ipc->sendToParent('{"event":"burst","seq":' . $i . '}');
            }

            $ipc->closeChild();
            exit(0);
        }

        // Parent
        $ipc->setupParent(
            function ($data) use (&$receivedMessages, $loop, $burstCount) {
                $receivedMessages[] = json_decode($data, true);
                if (count($receivedMessages) >= $burstCount) {
                    $loop->stop();
                }
            },
            function () use ($loop) {
                $loop->stop();
            }
        );

        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        $this->assertCount($burstCount, $receivedMessages);

        // Verify sequence order is preserved
        for ($i = 0; $i < $burstCount; $i++) {
            $this->assertEquals($i, $receivedMessages[$i]['seq'], "Sequence mismatch at position {$i}");
        }
    }

    /**
     * Test message integrity (no data corruption)
     */
    public function test_message_integrity_is_preserved()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        // Messages with special characters that could cause issues
        $testMessages = [
            '{"event":"test","data":"hello world"}',
            '{"event":"special","data":"line1\\nline2\\ttab"}',
            '{"event":"unicode","data":"Привет мир 你好世界 🎉"}',
            '{"event":"quotes","data":"He said \\"hello\\""}',
            '{"event":"backslash","data":"path\\\\to\\\\file"}',
            '{"event":"empty","data":""}',
            '{"event":"numbers","data":12345.6789}',
            '{"event":"boolean","data":true}',
            '{"event":"null","data":null}',
            '{"event":"array","data":[1,2,3]}',
        ];

        $receivedMessages = [];

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            foreach ($testMessages as $msg) {
                $ipc->sendToParent($msg);
            }

            $ipc->closeChild();
            exit(0);
        }

        $expectedCount = count($testMessages);
        $ipc->setupParent(
            function ($data) use (&$receivedMessages, $loop, $expectedCount) {
                $receivedMessages[] = $data;
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

        $this->assertCount(count($testMessages), $receivedMessages);

        // Verify each message matches exactly
        foreach ($testMessages as $i => $expected) {
            $this->assertEquals($expected, $receivedMessages[$i], "Message {$i} mismatch");
        }
    }

    /**
     * Test concurrent fork/IPC operations (simulating multiple WebSocket connections)
     */
    public function test_multiple_concurrent_fork_operations()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $concurrentCount = 10;
        $messagesPerFork = 5;
        $allReceived = [];

        $startTime = microtime(true);

        // Simulate multiple concurrent WebSocket message handlers
        for ($conn = 0; $conn < $concurrentCount; $conn++) {
            $loop = LoopFactory::create();
            $ipc = SocketPairIpc::create($loop);

            $received = [];

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail("Fork failed for connection {$conn}");
            }

            if ($pid === 0) {
                $ipc->setupChild();

                for ($i = 0; $i < $messagesPerFork; $i++) {
                    $ipc->sendToParent(json_encode([
                        'connection' => $conn,
                        'message' => $i,
                    ]));
                }

                $ipc->closeChild();
                exit(0);
            }

            $ipc->setupParent(
                function ($data) use (&$received, $loop, $messagesPerFork) {
                    $received[] = json_decode($data, true);
                    if (count($received) >= $messagesPerFork) {
                        $loop->stop();
                    }
                },
                function () use ($loop) {
                    $loop->stop();
                }
            );

            $loop->addTimer(1.0, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            pcntl_waitpid($pid, $status);

            $allReceived = array_merge($allReceived, $received);
        }

        $totalTime = microtime(true) - $startTime;

        $expectedTotal = $concurrentCount * $messagesPerFork;
        $this->assertCount($expectedTotal, $allReceived, "Expected {$expectedTotal} messages");

        fwrite(STDERR, "\n  [{$concurrentCount} connections × {$messagesPerFork} msgs = " . count($allReceived) . " total in " . round($totalTime, 3) . "s] ");
    }

    /**
     * Test latency distribution across many messages
     */
    public function test_latency_distribution()
    {
        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('Socket pairs not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $loop = LoopFactory::create();
        $ipc = SocketPairIpc::create($loop);

        $messageCount = 100;
        $latencies = [];

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Fork failed');
        }

        if ($pid === 0) {
            $ipc->setupChild();

            for ($i = 0; $i < $messageCount; $i++) {
                // Include send timestamp in message
                $ipc->sendToParent(json_encode([
                    'seq' => $i,
                    'sent_at' => microtime(true),
                ]));
            }

            $ipc->closeChild();
            exit(0);
        }

        $ipc->setupParent(
            function ($data) use (&$latencies, $loop, $messageCount) {
                $receivedAt = microtime(true);
                $msg = json_decode($data, true);
                if (isset($msg['sent_at'])) {
                    $latencies[] = ($receivedAt - $msg['sent_at']) * 1000; // ms
                }
                if (count($latencies) >= $messageCount) {
                    $loop->stop();
                }
            },
            function () use ($loop) {
                $loop->stop();
            }
        );

        $loop->addTimer(5.0, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        pcntl_waitpid($pid, $status);

        $this->assertCount($messageCount, $latencies);

        // Calculate statistics
        sort($latencies);
        $min = $latencies[0];
        $max = $latencies[count($latencies) - 1];
        $avg = array_sum($latencies) / count($latencies);
        $p50 = $latencies[(int)(count($latencies) * 0.50)];
        $p95 = $latencies[(int)(count($latencies) * 0.95)];
        $p99 = $latencies[(int)(count($latencies) * 0.99)];

        // All latencies should be under 10ms (event-driven should be fast)
        $this->assertLessThan(10, $p99, "P99 latency {$p99}ms exceeds 10ms");

        fwrite(STDERR, "\n  [Latency: min=" . round($min, 3) . "ms, avg=" . round($avg, 3) . "ms, p50=" . round($p50, 3) . "ms, p95=" . round($p95, 3) . "ms, p99=" . round($p99, 3) . "ms, max=" . round($max, 3) . "ms] ");
    }
}
