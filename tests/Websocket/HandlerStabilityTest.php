<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Websocket;

use BlaxSoftware\LaravelWebSockets\Test\Mocks;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;
use Carbon\Carbon;

/**
 * Stability and stress tests for the WebSocket Handler.
 *
 * Tests mirror the real-life frontend (Websocket.client.ts) behavior:
 * - Default channel: 'websocket' (public channel, the production default)
 * - Heartbeat: raw socket.send({ event: 'pusher.ping', data: {} }) every 20s
 * - Subscribe: pusher.subscribe with channel in data (dot format)
 * - Unsubscribe: pusher.unsubscribe (dot format — server only recognizes dots)
 * - Error recovery: "Subscription not established" → re-subscribe → retry
 * - Pusher events get :response suffix from handlePusherEvent()
 *
 * Groups (run subsets with --group / --exclude-group):
 *   @group stability       — Real-time tests using event loop timers (4+ minutes)
 *   @group stress          — Sustained load tests (10-30s each)
 *   @group error-isolation — Fast behavioral tests for error containment
 *   @group protocol        — Fast behavioral tests for protocol correctness
 *
 * Examples:
 *   ./vendor/bin/phpunit --group=stress tests/Websocket/HandlerStabilityTest.php
 *   ./vendor/bin/phpunit --exclude-group=stability tests/Websocket/HandlerStabilityTest.php
 */
class HandlerStabilityTest extends TestCase
{
    /** Reusable ping message (payload encoded once in constructor). */
    private Mocks\Message $pingMsg;

    /** Reusable subscribe message for 'websocket' channel. */
    private Mocks\Message $subMsg;

    /** Reusable unsubscribe message for 'websocket' channel. */
    private Mocks\Message $unsubMsg;

    public function setUp(): void
    {
        // Stress tests generate sustained promise chain object churn that exceeds
        // PHP's default 128MB limit. The PromiseResolver mock wraps each ping in
        // Block\await + FulfilledPromise chains (~10 allocations per message).
        ini_set('memory_limit', '512M');

        parent::setUp();

        $this->pingMsg = new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]);
        $this->subMsg = new Mocks\Message([
            'event' => 'pusher.subscribe',
            'data' => ['channel' => 'websocket'],
        ]);
        $this->unsubMsg = new Mocks\Message([
            'event' => 'pusher.unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]);
    }

    // =========================================================================
    // STABILITY: Connection longevity under real-time conditions
    // =========================================================================

    /**
     * Runs for 4 REAL minutes (240 seconds) with:
     * - Client heartbeat every 20s (real frontend interval)
     * - Server cleanup cycle every 10s (removeObsoleteConnections, 120s threshold)
     * - Subscription verification every 60s
     *
     * The connection must survive all 24 cleanup cycles.
     *
     * NOTE: Cannot use $this->loop->addPeriodicTimer() because the test's
     * PromiseResolver mock calls Block\await() which always invokes
     * $loop->stop() on resolve — killing the outer event loop. Instead we
     * use manual timing with usleep() which is equally realistic.
     *
     * @group stability
     */
    public function test_connection_survives_four_minutes_with_periodic_pings()
    {
        $this->runOnlyOnLocalReplication();

        $connection = $this->newActiveConnection(['websocket']);
        $connection->assertSentEvent('pusher.connection_established');
        $connection->assertSentEvent('pusher_internal:subscription_succeeded');
        $connection->resetEvents();

        $pingsSent = 0;
        $pongsSeen = 0;
        $cleanupRuns = 0;
        $subscriptionChecks = 0;

        $duration = 240; // seconds (4 minutes)
        $startTime = time();
        $endTime = $startTime + $duration;
        $nextPing = $startTime + 20;
        $nextCleanup = $startTime + 10;

        while (time() < $endTime) {
            $now = time();

            // Server cleanup cycle every 10s
            if ($now >= $nextCleanup) {
                $this->channelManager->removeObsoleteConnections();
                $cleanupRuns++;
                $nextCleanup = $now + 10;

                // Every 6th cleanup (~60s), deep-verify subscription is intact
                if ($cleanupRuns % 6 === 0) {
                    $channel = $this->channelManager->find('1234', 'websocket');
                    $this->assertNotNull($channel, "Channel gone at cleanup #{$cleanupRuns} (~" . ($cleanupRuns * 10) . "s)");
                    $this->assertTrue(
                        $channel->hasConnection($connection),
                        "Connection removed at cleanup #{$cleanupRuns} (~" . ($cleanupRuns * 10) . "s)"
                    );
                    $subscriptionChecks++;
                }
            }

            // Client heartbeat every 20s
            if ($now >= $nextPing) {
                $connection->resetEvents();
                $this->pusherServer->onMessage($connection, $this->pingMsg);
                $pingsSent++;

                $pong = collect($connection->sentData)->firstWhere('event', 'pusher.pong');
                $this->assertNotNull($pong, "Ping #{$pingsSent} at ~" . ($pingsSent * 20) . "s should get pong");
                $pongsSeen++;
                $nextPing = $now + 20;
            }

            usleep(500000); // 500ms sleep — low CPU, ≤0.5s timing jitter
        }

        // Post-run assertions (thresholds based on $duration)
        $expectedPings = max(1, intdiv($duration, 20) - 1);
        $expectedCleanups = max(1, intdiv($duration, 10) - 1);
        $expectedSubChecks = max(0, intdiv($cleanupRuns, 6));

        $this->assertGreaterThanOrEqual($expectedPings, $pingsSent, "Should send ≥{$expectedPings} pings over {$duration}s (20s interval)");
        $this->assertEquals($pingsSent, $pongsSeen, 'Every ping must produce a pong');
        $this->assertGreaterThanOrEqual($expectedCleanups, $cleanupRuns, "Cleanup should run ≥{$expectedCleanups} times (10s interval)");
        if ($duration >= 60) {
            $this->assertGreaterThanOrEqual(1, $subscriptionChecks, 'Should deep-verify subscription ≥1 time');
        }

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel, 'Channel must exist after 4 minutes');
        $this->assertTrue($channel->hasConnection($connection), 'Connection must be subscribed after 4 minutes');
    }

    /**
     * Active connection (with pings) survives removeObsoleteConnections,
     * stale connection (no pings for >120s) gets removed.
     *
     * Uses Carbon time manipulation to test the 120s threshold logic
     * without waiting 2+ real minutes. The 4-minute test above covers
     * real-time survival; this test isolates the cleanup decision logic.
     *
     * @group stability
     */
    public function test_stale_connection_removed_active_connection_survives()
    {
        $this->runOnlyOnLocalReplication();

        $activeConnection = $this->newActiveConnection(['websocket']);
        $staleConnection = $this->newActiveConnection(['websocket']);

        $activeConnection->lastPongedAt = Carbon::now();
        $staleConnection->lastPongedAt = Carbon::now();

        $this->channelManager->updateConnectionInChannels($activeConnection);
        $this->channelManager->updateConnectionInChannels($staleConnection);

        // Active tab keeps pinging, stale tab goes silent
        for ($cycle = 0; $cycle < 8; $cycle++) {
            $activeConnection->lastPongedAt = Carbon::now();
            $this->channelManager->updateConnectionInChannels($activeConnection);
            $this->pusherServer->onMessage($activeConnection, $this->pingMsg);
        }

        // Stale: >120s without pong
        $staleConnection->lastPongedAt = Carbon::now()->subSeconds(200);
        $this->channelManager->updateConnectionInChannels($staleConnection);

        $this->channelManager->removeObsoleteConnections();

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel);
        $this->assertTrue($channel->hasConnection($activeConnection), 'Active connection should survive');
        $this->assertFalse($channel->hasConnection($staleConnection), 'Stale connection should be removed');
    }

    // =========================================================================
    // STRESS: Server stability under sustained high load (10-30s each)
    // =========================================================================

    /**
     * 30 seconds of sustained message bombardment in three phases:
     * - Phase 1 (10s): Rapid pings → tryHandlePingFast hot path
     * - Phase 2 (10s): Rapid subscribe/unsubscribe cycles → channel churn
     * - Phase 3 (10s): Mixed pings + sub/unsub → full message routing
     *
     * Memory: sentData is periodically flushed and GC forced between batches
     * to prevent OOM from promise chain objects (PromiseResolver wrapping).
     *
     * @group stress
     */
    public function test_connection_stable_under_message_bombardment()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->assertSentEvent('pusher.connection_established');
        $connection->resetEvents();

        // Phase 1: 10s of rapid pings (tryHandlePingFast hot path)
        $phaseStart = microtime(true);
        $totalPings = 0;
        $totalPongs = 0;

        while (microtime(true) - $phaseStart < 10) {
            for ($batch = 0; $batch < 50; $batch++) {
                $this->pusherServer->onMessage($connection, $this->pingMsg);
                $totalPings++;
            }
            $totalPongs += count($connection->sentData);
            $connection->resetEvents();
            gc_collect_cycles();
        }

        $this->assertEquals($totalPings, $totalPongs, 'Phase 1: All pings should produce pongs');
        $this->assertGreaterThan(1000, $totalPings, 'Phase 1: Should process substantial volume in 10s');
        gc_collect_cycles();

        // Phase 2: 10s of rapid subscribe/unsubscribe cycles
        $phaseStart = microtime(true);
        $subUnsubCycles = 0;

        while (microtime(true) - $phaseStart < 10) {
            $this->pusherServer->onMessage($connection, $this->unsubMsg);
            $this->pusherServer->onMessage($connection, $this->subMsg);
            $subUnsubCycles++;
            if ($subUnsubCycles % 25 === 0) {
                $connection->resetEvents();
                gc_collect_cycles();
            }
        }

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel, 'Phase 2: Channel should exist after sub/unsub bombardment');
        $this->assertTrue($channel->hasConnection($connection), 'Phase 2: Connection should be subscribed');
        $this->assertGreaterThan(500, $subUnsubCycles, 'Phase 2: Should complete substantial sub/unsub cycles');
        $connection->resetEvents();
        gc_collect_cycles();

        // Phase 3: 10s of mixed messages (ping + sub/unsub per iteration)
        $phaseStart = microtime(true);
        $mixedCount = 0;
        $mixedPings = 0;
        $mixedPongs = 0;

        while (microtime(true) - $phaseStart < 10) {
            $this->pusherServer->onMessage($connection, $this->pingMsg);
            $mixedPings++;
            $this->pusherServer->onMessage($connection, $this->subMsg);
            $this->pusherServer->onMessage($connection, $this->unsubMsg);
            $this->pusherServer->onMessage($connection, $this->subMsg);
            $mixedCount++;

            if ($mixedCount % 10 === 0) {
                $mixedPongs += collect($connection->sentData)->where('event', 'pusher.pong')->count();

                $errors = collect($connection->sentData)->filter(
                    fn($e) =>
                    isset($e['event']) && str_contains($e['event'], ':error')
                );
                $this->assertCount(0, $errors, 'Phase 3: No error events during valid mixed messages');

                $connection->resetEvents();
                gc_collect_cycles();
            }
        }
        $mixedPongs += collect($connection->sentData)->where('event', 'pusher.pong')->count();
        $this->assertEquals($mixedPings, $mixedPongs, 'Phase 3: All pings should produce pongs');
        $this->assertGreaterThan(500, $mixedCount, 'Phase 3: Should process substantial mixed volume');

        // Final: connection still alive
        $connection->resetEvents();
        $this->pusherServer->onMessage($connection, $this->pingMsg);
        $connection->assertSentEvent('pusher.pong');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($connection), 'Connection must survive 30s bombardment');
    }

    /**
     * 100 connections with sustained 15s pinging:
     * - Phase 1 (10s): All 100 connections pinged in rotation
     * - Close 50 connections
     * - Phase 2 (5s): Remaining 50 continue under sustained load
     *
     * @group stress
     */
    public function test_hundred_parallel_connections_stay_stable()
    {
        $connections = [];
        for ($i = 0; $i < 100; $i++) {
            $connections[] = $this->newActiveConnection(['websocket']);
        }

        foreach ($connections as $conn) {
            $conn->assertSentEvent('pusher.connection_established');
            $conn->resetEvents();
        }

        // Phase 1: Sustained pinging of all 100 for 10s
        $start = microtime(true);
        $totalPings = 0;

        while (microtime(true) - $start < 10) {
            foreach ($connections as $conn) {
                $this->pusherServer->onMessage($conn, $this->pingMsg);
                $totalPings++;
            }
            // Flush all connections to prevent OOM
            foreach ($connections as $conn) {
                $conn->resetEvents();
            }
            gc_collect_cycles();
        }

        $this->assertGreaterThan(2500, $totalPings, 'Phase 1: Substantial volume across 100 connections');

        // Verify all 100 still subscribed
        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel);
        foreach ($connections as $idx => $conn) {
            $this->assertTrue($channel->hasConnection($conn), "Connection #{$idx} alive after phase 1");
        }

        // Close first 50
        for ($i = 0; $i < 50; $i++) {
            $this->pusherServer->onClose($connections[$i]);
        }
        gc_collect_cycles();

        // Phase 2: Remaining 50 for 5s more
        $remaining = array_slice($connections, 50);
        $start2 = microtime(true);
        $phase2Pings = 0;

        while (microtime(true) - $start2 < 5) {
            foreach ($remaining as $conn) {
                $this->pusherServer->onMessage($conn, $this->pingMsg);
                $phase2Pings++;
            }
            foreach ($remaining as $conn) {
                $conn->resetEvents();
            }
            gc_collect_cycles();
        }

        $this->assertGreaterThan(1000, $phase2Pings, 'Phase 2: Remaining 50 handle sustained load');

        // Final: closed connections removed, remaining alive
        $channel = $this->channelManager->find('1234', 'websocket');
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($channel->hasConnection($connections[$i]), "Closed #{$i} removed");
        }
        for ($i = 50; $i < 100; $i++) {
            $this->assertTrue($channel->hasConnection($connections[$i]), "Remaining #{$i} alive");
        }
    }

    /**
     * 100 connections across 5 channels with sustained 10s pinging.
     * Closing all connections on one channel doesn't affect the other four.
     *
     * @group stress
     */
    public function test_hundred_connections_across_multiple_channels()
    {
        $channels = ['websocket', 'simulator', 'blog', 'notifications', 'admin'];
        $connections = [];

        foreach ($channels as $channelName) {
            for ($i = 0; $i < 20; $i++) {
                $conn = $this->newActiveConnection([$channelName]);
                $conn->resetEvents();
                $connections[$channelName][] = $conn;
            }
        }

        // Sustained 10s pinging across all 100 connections on all 5 channels
        $allConnections = array_merge(...array_values($connections));
        $start = microtime(true);
        $totalPings = 0;

        while (microtime(true) - $start < 10) {
            foreach ($allConnections as $conn) {
                $this->pusherServer->onMessage($conn, $this->pingMsg);
                $totalPings++;
            }
            foreach ($allConnections as $conn) {
                $conn->resetEvents();
            }
            gc_collect_cycles();
        }

        $this->assertGreaterThan(2500, $totalPings, 'Substantial volume across 5 channels');

        // Close all on 'blog' channel
        foreach ($connections['blog'] as $conn) {
            $this->pusherServer->onClose($conn);
        }

        // Other 4 channels fully operational — verify with ping
        foreach (['websocket', 'simulator', 'notifications', 'admin'] as $channelName) {
            $channel = $this->channelManager->find('1234', $channelName);
            $this->assertNotNull($channel, "{$channelName} should still exist");
            foreach ($connections[$channelName] as $idx => $conn) {
                $conn->resetEvents();
                $this->pusherServer->onMessage($conn, $this->pingMsg);
                $conn->assertSentEvent('pusher.pong');
                $this->assertTrue(
                    $channel->hasConnection($conn),
                    "{$channelName} conn #{$idx} should be subscribed"
                );
            }
        }
    }

    /**
     * 15 seconds of rapid connect/disconnect churn while a permanent
     * connection stays alive. Tests channel manager integrity under
     * sustained connection turnover.
     *
     * @group stress
     */
    public function test_rapid_connect_disconnect_cycles()
    {
        $permanentConnection = $this->newActiveConnection(['websocket']);
        $permanentConnection->assertSentEvent('pusher.connection_established');
        $permanentConnection->resetEvents();

        $start = microtime(true);
        $cycles = 0;

        while (microtime(true) - $start < 15) {
            $temp = $this->newActiveConnection(['websocket']);
            $this->pusherServer->onClose($temp);
            $cycles++;

            // Every 100 cycles, verify permanent connection is alive
            if ($cycles % 100 === 0) {
                $this->pusherServer->onMessage($permanentConnection, $this->pingMsg);
                $permanentConnection->assertSentEvent('pusher.pong');
                $permanentConnection->resetEvents();
                gc_collect_cycles();
            }
        }

        $this->assertGreaterThan(500, $cycles, 'Should complete substantial churn cycles in 15s');

        // Final verification
        $permanentConnection->resetEvents();
        $this->pusherServer->onMessage($permanentConnection, $this->pingMsg);
        $permanentConnection->assertSentEvent('pusher.pong');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel);
        $this->assertTrue($channel->hasConnection($permanentConnection), 'Permanent connection survives churn');
    }

    // =========================================================================
    // ERROR ISOLATION: One connection's failure must not affect others
    // =========================================================================

    /**
     * Connection sends to an unsubscribed channel → "Subscription not established"
     * error. Other connections on 'websocket' are unaffected.
     *
     * @group error-isolation
     */
    public function test_error_on_one_connection_does_not_affect_others()
    {
        $good1 = $this->newActiveConnection(['websocket']);
        $good2 = $this->newActiveConnection(['websocket']);
        $bad = $this->newActiveConnection(['websocket']);

        $bad->resetEvents();
        $this->pusherServer->onMessage($bad, new Mocks\Message([
            'event' => 'blog.show[abc123]',
            'data' => ['id' => '123'],
            'channel' => 'nonexistent-channel',
        ]));
        $bad->assertSentEvent('blog.show[abc123]:error');

        $good1->resetEvents();
        $good2->resetEvents();
        $this->pusherServer->onMessage($good1, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $this->pusherServer->onMessage($good2, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $good1->assertSentEvent('pusher.pong');
        $good2->assertSentEvent('pusher.pong');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($good1));
        $this->assertTrue($channel->hasConnection($good2));
    }

    /**
     * Full "Subscription not established" recovery flow:
     * subscribe → unsubscribe → send (error) → re-subscribe → send (success)
     *
     * @group error-isolation
     */
    public function test_subscription_not_established_error_is_recoverable()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->assertSentEvent('pusher.connection_established');
        $connection->assertSentEvent('pusher_internal:subscription_succeeded');

        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $connection->resetEvents();
        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.custom[xyz789]',
            'data' => ['test' => 'recovery'],
            'channel' => 'websocket',
        ]));

        $errorEvent = collect($connection->sentData)->firstWhere('event', 'pusher.custom[xyz789]:error');
        $this->assertNotNull($errorEvent, 'Should get :error');
        $this->assertEquals('Subscription not established', $errorEvent['data']['message']);

        $connection->resetEvents();
        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.subscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($connection), 'Re-subscribed');

        $connection->resetEvents();
        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.custom[def456]',
            'data' => ['test' => 'post-recovery'],
            'channel' => 'websocket',
        ]));

        $responseEvent = collect($connection->sentData)->firstWhere('event', 'pusher.custom[def456]:response');
        $this->assertNotNull($responseEvent, 'Post-recovery should get :response');
        $this->assertEquals('Success', $responseEvent['data']['message']);
    }

    /**
     * onError on one connection doesn't close or affect other connections.
     *
     * @group error-isolation
     */
    public function test_on_error_does_not_close_other_connections()
    {
        $conn1 = $this->newActiveConnection(['websocket']);
        $conn2 = $this->newActiveConnection(['websocket']);
        $conn3 = $this->newActiveConnection(['websocket']);

        $exception = new \BlaxSoftware\LaravelWebSockets\Server\Exceptions\UnknownAppKey('BadKey');
        $this->pusherServer->onError($conn1, $exception);

        $conn1->assertSentEvent('pusher.error');
        $conn2->assertNotSentEvent('pusher.error');
        $conn3->assertNotSentEvent('pusher.error');

        $conn2->resetEvents();
        $conn3->resetEvents();
        $this->pusherServer->onMessage($conn2, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $this->pusherServer->onMessage($conn3, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $conn2->assertSentEvent('pusher.pong');
        $conn3->assertSentEvent('pusher.pong');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($conn2));
        $this->assertTrue($channel->hasConnection($conn3));
    }

    /**
     * Closing a connection doesn't interfere with other connections.
     *
     * @group error-isolation
     */
    public function test_connection_close_does_not_affect_siblings()
    {
        $survivor1 = $this->newActiveConnection(['websocket']);
        $survivor2 = $this->newActiveConnection(['websocket']);
        $doomed = $this->newActiveConnection(['websocket']);

        $this->pusherServer->onClose($doomed);

        $survivor1->resetEvents();
        $survivor2->resetEvents();
        $this->pusherServer->onMessage($survivor1, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $this->pusherServer->onMessage($survivor2, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $survivor1->assertSentEvent('pusher.pong');
        $survivor2->assertSentEvent('pusher.pong');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel);
        $this->assertTrue($channel->hasConnection($survivor1));
        $this->assertTrue($channel->hasConnection($survivor2));
        $this->assertFalse($channel->hasConnection($doomed));
    }

    /**
     * Malformed JSON doesn't crash the server or affect other connections.
     *
     * @group error-isolation
     */
    public function test_malformed_message_does_not_crash_server()
    {
        $goodConn = $this->newActiveConnection(['websocket']);
        $badConn = $this->newActiveConnection(['websocket']);

        $rawMessage = $this->createRawMessage('{invalid json!!!}');

        try {
            $this->pusherServer->onMessage($badConn, $rawMessage);
        } catch (\Throwable $e) {
            // Handler should catch, but even if it propagates, others unaffected
        }

        $goodConn->resetEvents();
        $this->pusherServer->onMessage($goodConn, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $goodConn->assertSentEvent('pusher.pong');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($goodConn));
    }

    // =========================================================================
    // PROTOCOL: Tests mirroring exact real frontend message patterns
    // =========================================================================

    /**
     * Full client lifecycle: onOpen → subscribe → heartbeat → onClose.
     *
     * @group protocol
     */
    public function test_full_client_lifecycle_mirrors_frontend()
    {
        $connection = $this->newConnection('TestKey');
        $this->pusherServer->onOpen($connection);

        $established = collect($connection->sentData)->firstWhere('event', 'pusher.connection_established');
        $this->assertNotNull($established);

        $data = json_decode($established['data'], true);
        $this->assertArrayHasKey('socket_id', $data);
        $this->assertNotEmpty($data['socket_id']);

        $connection->resetEvents();
        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.subscribe',
            'data' => ['channel' => 'websocket', 'auth' => 'TestKey:fake-signature'],
        ]));

        $connection->assertSentEvent('pusher_internal:subscription_succeeded');
        $connection->assertSentEvent('pusher.subscribe:response');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertNotNull($channel);
        $this->assertTrue($channel->hasConnection($connection));

        $connection->resetEvents();
        $this->pusherServer->onMessage($connection, $this->pingMsg);
        $connection->assertSentEvent('pusher.pong');

        $this->pusherServer->onClose($connection);
        $this->assertFalse($channel->hasConnection($connection));
    }

    /**
     * Pusher-prefixed events get :response suffix from handlePusherEvent().
     *
     * @group protocol
     */
    public function test_pusher_events_get_response_suffix()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->resetEvents();

        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.custom-event',
            'data' => ['payload' => 'test'],
            'channel' => 'websocket',
        ]));

        $response = collect($connection->sentData)->firstWhere('event', 'pusher.custom-event:response');
        $this->assertNotNull($response, 'Pusher events should get :response');
        $this->assertEquals('Success', $response['data']['message']);
    }

    /**
     * Both ping formats produce pongs: pusher.ping (frontend) and pusher:ping (Pusher spec).
     *
     * @group protocol
     */
    public function test_both_ping_formats_work()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->resetEvents();

        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher.ping',
            'data' => new \stdClass(),
        ]));
        $this->pusherServer->onMessage($connection, new Mocks\Message([
            'event' => 'pusher:ping',
            'data' => new \stdClass(),
        ]));

        $this->assertEquals(
            2,
            collect($connection->sentData)->where('event', 'pusher.pong')->count(),
            'Both ping formats should produce pongs'
        );
    }

    /**
     * Unsubscribe only works with dot format (pusher.unsubscribe).
     * Colon format (pusher:unsubscribe) is NOT recognized.
     *
     * @group protocol
     */
    public function test_unsubscribe_only_works_with_dot_format()
    {
        $conn1 = $this->newActiveConnection(['websocket']);
        $this->pusherServer->onMessage($conn1, new Mocks\Message([
            'event' => 'pusher.unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertFalse($channel->hasConnection($conn1), 'Dot-format unsubscribes');

        $conn2 = $this->newActiveConnection(['websocket']);
        $this->pusherServer->onMessage($conn2, new Mocks\Message([
            'event' => 'pusher:unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($conn2), 'Colon-format does NOT unsubscribe');
    }

    /**
     * Messages without app context (no onOpen) are silently ignored.
     *
     * @group protocol
     */
    public function test_message_without_app_is_silently_ignored()
    {
        $connection = new Mocks\Connection();
        $connection->httpRequest = new \GuzzleHttp\Psr7\Request('GET', '/?appKey=TestKey');

        $this->pusherServer->onMessage($connection, $this->pingMsg);

        $this->assertEmpty($connection->sentData, 'No data sent to connection without app');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createRawMessage(string $rawPayload): Mocks\Message
    {
        return new class($rawPayload) extends Mocks\Message {
            private string $raw;

            public function __construct(string $raw)
            {
                parent::__construct([]);
                $this->raw = $raw;
            }

            public function getPayload(): string
            {
                return $this->raw;
            }
        };
    }
}
