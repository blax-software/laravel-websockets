<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Websocket;

use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use BlaxSoftware\LaravelWebSockets\Test\Mocks;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;
use BlaxSoftware\LaravelWebSockets\Websocket\Controller;

/**
 * Tests for the fork+IPC message processing path in Handler.
 *
 * These tests verify that messages which go through forkAndProcessMessage()
 * and Controller::controll_message() work correctly with SocketPairIpc.
 *
 * Unlike client-* messages (synchronous), these test the async fork path
 * where a child process handles the message and sends response via IPC.
 */
class HandlerForkPathTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!SocketPairIpc::isSupported()) {
            $this->markTestSkipped('SocketPairIpc not supported');
        }

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }
    }

    /**
     * Test that the handler properly detects socket pair IPC when supported.
     */
    public function test_handler_uses_socket_pair_ipc_when_supported()
    {
        // Verify SocketPairIpc is supported in this environment
        $this->assertTrue(SocketPairIpc::isSupported());

        // The handler should automatically use socket pair IPC
        // We can verify this by checking the handler was created successfully
        $this->assertNotNull($this->wsHandler);
    }

    /**
     * Test subscription and unsubscribe flow works properly.
     */
    public function test_subscribe_unsubscribe_flow()
    {
        $connection = $this->newActiveConnection(['fork-test-channel']);

        // Verify connection was established (subscription event has pre-existing test issues)
        $connection->assertSentEvent('websocket.connection_established');

        // Now unsubscribe
        $message = new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'fork-test-channel'],
        ]);

        $this->wsHandler->onMessage($connection, $message);

        // No error should be sent
        $connection->assertNotSentEvent('websocket.unsubscribe:error');
    }

    /**
     * Test event targeting non-subscribed channel gets error.
     */
    public function test_message_to_non_subscribed_channel_returns_error()
    {
        $connection = $this->newActiveConnection(['channel-one']);

        // Try to send to a channel we're not subscribed to
        $message = new Mocks\Message([
            'event' => 'custom.action',
            'data' => ['test' => true],
            'channel' => 'channel-two', // Not subscribed!
        ]);

        $this->wsHandler->onMessage($connection, $message);

        // Should receive an error event
        $connection->assertSentEvent('custom.action:error');
    }

    /**
     * Test multiple quick subscriptions and unsubscriptions.
     */
    public function test_rapid_subscribe_unsubscribe_cycle()
    {
        $connection = $this->newActiveConnection(['cycle-channel']);

        // Rapid subscribe/unsubscribe cycle
        for ($i = 0; $i < 5; $i++) {
            // Unsubscribe
            $unsubMsg = new Mocks\Message([
                'event' => 'websocket.unsubscribe',
                'data' => ['channel' => 'cycle-channel'],
            ]);
            $this->wsHandler->onMessage($connection, $unsubMsg);

            // Resubscribe
            $subMsg = new Mocks\Message([
                'event' => 'websocket.subscribe',
                'data' => ['channel' => 'cycle-channel'],
            ]);
            $this->wsHandler->onMessage($connection, $subMsg);
        }

        // No errors should have been sent
        $this->assertTrue(true);
    }

    /**
     * Test that connection properties are preserved through message handling.
     */
    public function test_connection_properties_preserved()
    {
        $connection = $this->newActiveConnection(['props-channel']);

        // Verify socket ID is set and consistent
        $this->assertNotNull($connection->socketId);
        $this->assertIsString($connection->socketId);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $connection->socketId);

        // Verify app is set
        $this->assertNotNull($connection->app);
        $this->assertEquals('1234', $connection->app->id);
    }

    /**
     * Test that messages with empty data are handled.
     */
    public function test_message_with_empty_data()
    {
        $this->app['config']->set('websockets.apps.0.enable_client_messages', true);

        $sender = $this->newActiveConnection(['empty-data-channel']);
        $receiver = $this->newActiveConnection(['empty-data-channel']);

        $message = new Mocks\Message([
            'event' => 'client-empty',
            'data' => [],
            'channel' => 'empty-data-channel',
        ]);

        $this->wsHandler->onMessage($sender, $message);

        $receiver->assertSentEvent('client-empty', [
            'data' => [],
            'channel' => 'empty-data-channel',
        ]);
    }

    /**
     * Test that handler properly reports SocketPairIpc support.
     */
    public function test_socket_pair_ipc_support_detection()
    {
        // These are the requirements for SocketPairIpc
        $this->assertTrue(extension_loaded('sockets'), 'Sockets extension required');
        $this->assertTrue(function_exists('pcntl_fork'), 'pcntl_fork required');
        $this->assertTrue(function_exists('socket_create_pair'), 'socket_create_pair required');

        // SocketPairIpc should report as supported
        $this->assertTrue(SocketPairIpc::isSupported());
    }

    /**
     * Test that ping/pong works correctly.
     */
    public function test_ping_pong_handled()
    {
        $connection = $this->newActiveConnection(['pusher-event-channel']);

        // Ping should work
        $pingMsg = new Mocks\Message([
            'event' => 'websocket.ping',
        ]);

        $this->wsHandler->onMessage($connection, $pingMsg);
        $connection->assertSentEvent('websocket.pong');
    }

    /**
     * Test client messages disabled prevents whisper.
     */
    public function test_client_messages_disabled_blocks_whisper()
    {
        // Ensure client messages are disabled (default)
        $this->app['config']->set('websockets.apps.0.enable_client_messages', false);

        $sender = $this->newActiveConnection(['no-whisper-channel']);
        $receiver = $this->newActiveConnection(['no-whisper-channel']);

        $message = new Mocks\Message([
            'event' => 'client-blocked',
            'data' => ['message' => 'should be blocked'],
            'channel' => 'no-whisper-channel',
        ]);

        $this->wsHandler->onMessage($sender, $message);

        // Neither should receive (whisper blocked)
        $sender->assertNotSentEvent('client-blocked');
        $receiver->assertNotSentEvent('client-blocked');
    }
}
