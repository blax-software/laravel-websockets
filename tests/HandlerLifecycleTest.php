<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;

/**
 * Comprehensive tests for the full WebSocket Handler lifecycle.
 *
 * These tests use the TestCase helper methods (newConnection, newActiveConnection,
 * newPrivateConnection, newPresenceConnection) to exercise the Handler's
 * connection management, channel subscriptions, and message routing.
 *
 * Covers:
 * - Connection lifecycle (open, close, error)
 * - Public/private/presence channel subscribe/unsubscribe
 * - Ping/pong heartbeat
 * - Protocol event responses
 * - Connection isolation (errors don't leak across connections)
 * - Cache tracking updates via Handler
 */
class HandlerLifecycleTest extends TestCase
{
    // =========================================================================
    // Connection establishment
    // =========================================================================

    public function test_new_connection_receives_connection_established_event()
    {
        $connection = $this->newConnection();
        $this->wsHandler->onOpen($connection);

        $connection->assertSentEvent('websocket.connection_established');
    }

    public function test_connection_established_contains_socket_id()
    {
        $connection = $this->newConnection();
        $this->wsHandler->onOpen($connection);

        $event = collect($connection->sentData)->firstWhere('event', 'websocket.connection_established');
        $data = json_decode($event['data'], true);

        $this->assertNotNull($data['socket_id']);
        $this->assertStringContainsString('.', $data['socket_id']);
    }

    public function test_connection_established_contains_activity_timeout()
    {
        $connection = $this->newConnection();
        $this->wsHandler->onOpen($connection);

        $event = collect($connection->sentData)->firstWhere('event', 'websocket.connection_established');
        $data = json_decode($event['data'], true);

        $this->assertEquals(30, $data['activity_timeout']);
    }

    public function test_multiple_connections_get_unique_socket_ids()
    {
        $conn1 = $this->newConnection();
        $conn2 = $this->newConnection();
        $conn3 = $this->newConnection();

        $this->wsHandler->onOpen($conn1);
        $this->wsHandler->onOpen($conn2);
        $this->wsHandler->onOpen($conn3);

        $socket1 = json_decode((collect($conn1->sentData)->firstWhere('event', 'websocket.connection_established')['data'] ?? '{}'), true)['socket_id'] ?? null;
        $socket2 = json_decode((collect($conn2->sentData)->firstWhere('event', 'websocket.connection_established')['data'] ?? '{}'), true)['socket_id'] ?? null;
        $socket3 = json_decode((collect($conn3->sentData)->firstWhere('event', 'websocket.connection_established')['data'] ?? '{}'), true)['socket_id'] ?? null;

        $this->assertNotNull($socket1);
        $this->assertNotNull($socket2);
        $this->assertNotNull($socket3);
        $this->assertNotEquals($socket1, $socket2);
        $this->assertNotEquals($socket2, $socket3);
        $this->assertNotEquals($socket1, $socket3);
    }

    // =========================================================================
    // Public channel subscribe/unsubscribe
    // =========================================================================

    public function test_subscribe_to_public_channel()
    {
        $connection = $this->newActiveConnection(['test-channel']);

        $connection->assertSentEvent('websocket_internal.subscription_succeeded');

        $channel = $this->channelManager->find('1234', 'test-channel');
        $this->assertTrue($channel->hasConnection($connection));
    }

    public function test_subscribe_to_multiple_public_channels()
    {
        $connection = $this->newActiveConnection(['channel-a', 'channel-b', 'channel-c']);

        $channelA = $this->channelManager->find('1234', 'channel-a');
        $channelB = $this->channelManager->find('1234', 'channel-b');
        $channelC = $this->channelManager->find('1234', 'channel-c');

        $this->assertTrue($channelA->hasConnection($connection));
        $this->assertTrue($channelB->hasConnection($connection));
        $this->assertTrue($channelC->hasConnection($connection));
    }

    public function test_unsubscribe_from_public_channel()
    {
        $connection = $this->newActiveConnection(['test-channel']);
        $channel = $this->channelManager->find('1234', 'test-channel');

        $this->assertTrue($channel->hasConnection($connection));

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'test-channel'],
        ]));

        $this->assertFalse($channel->hasConnection($connection));
    }

    public function test_unsubscribe_does_not_affect_other_channels()
    {
        $connection = $this->newActiveConnection(['channel-a', 'channel-b']);

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'channel-a'],
        ]));

        $channelA = $this->channelManager->find('1234', 'channel-a');
        $channelB = $this->channelManager->find('1234', 'channel-b');

        $this->assertFalse($channelA->hasConnection($connection));
        $this->assertTrue($channelB->hasConnection($connection));
    }

    // =========================================================================
    // Private channel subscribe/unsubscribe
    // =========================================================================

    public function test_subscribe_to_private_channel_with_valid_signature()
    {
        $connection = $this->newPrivateConnection('private-test');

        $connection->assertSentEvent('websocket_internal.subscription_succeeded');

        $channel = $this->channelManager->find('1234', 'private-test');
        $this->assertTrue($channel->hasConnection($connection));
    }

    public function test_subscribe_to_private_channel_with_invalid_signature_is_rejected()
    {
        $connection = $this->newConnection();
        $this->wsHandler->onOpen($connection);

        $message = new Mocks\Message([
            'event' => 'websocket.subscribe',
            'data' => [
                'auth' => 'invalid-signature',
                'channel' => 'private-test',
            ],
        ]);

        $this->wsHandler->onMessage($connection, $message);

        // Invalid signature is silently caught — no subscription_succeeded
        $connection->assertNotSentEvent('websocket_internal.subscription_succeeded');
    }

    // =========================================================================
    // Presence channel subscribe/unsubscribe
    // =========================================================================

    public function test_subscribe_to_presence_channel()
    {
        $connection = $this->newPresenceConnection('presence-chat', [
            'user_id' => 1,
            'user_info' => ['name' => 'Rick'],
        ]);

        $connection->assertSentEvent('websocket_internal.subscription_succeeded');

        $channel = $this->channelManager->find('1234', 'presence-chat');
        $this->assertTrue($channel->hasConnection($connection));
    }

    public function test_presence_channel_member_added_on_second_connection()
    {
        $rick = $this->newPresenceConnection('presence-chat', [
            'user_id' => 1,
            'user_info' => ['name' => 'Rick'],
        ]);

        $rick->resetEvents();

        $morty = $this->newPresenceConnection('presence-chat', [
            'user_id' => 2,
            'user_info' => ['name' => 'Morty'],
        ]);

        // Rick should receive a member_added event
        $rick->assertSentEvent('websocket_internal.member_added');
    }

    public function test_presence_channel_shows_member_count()
    {
        $rick = $this->newPresenceConnection('presence-chat', [
            'user_id' => 1,
            'user_info' => ['name' => 'Rick'],
        ]);

        $morty = $this->newPresenceConnection('presence-chat', [
            'user_id' => 2,
            'user_info' => ['name' => 'Morty'],
        ]);

        $channel = $this->channelManager->find('1234', 'presence-chat');
        $this->assertTrue($channel->hasConnection($rick));
        $this->assertTrue($channel->hasConnection($morty));
    }

    // =========================================================================
    // Ping/pong heartbeat
    // =========================================================================

    public function test_ping_receives_pong()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->resetEvents();

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.ping',
            'data' => new \stdClass(),
        ]));

        $connection->assertSentEvent('websocket.pong');
    }

    public function test_backward_compat_colon_ping_receives_pong()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->resetEvents();

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'pusher:ping',
            'data' => new \stdClass(),
        ]));

        $connection->assertSentEvent('websocket.pong');
    }

    public function test_ping_does_not_require_channel_subscription()
    {
        $connection = $this->newConnection();
        $this->wsHandler->onOpen($connection);
        $connection->resetEvents();

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.ping',
            'data' => new \stdClass(),
        ]));

        $connection->assertSentEvent('websocket.pong');
    }

    // =========================================================================
    // Protocol :response suffix
    // =========================================================================

    public function test_subscribe_gets_response_suffix()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->resetEvents();

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.subscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $response = collect($connection->sentData)->firstWhere('event', 'websocket.subscribe:response');
        $this->assertNotNull($response);
        $this->assertEquals('Success', $response['data']['message']);
    }

    public function test_unsubscribe_gets_response_suffix()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $connection->resetEvents();

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $response = collect($connection->sentData)->firstWhere('event', 'websocket.unsubscribe:response');
        $this->assertNotNull($response);
    }

    // =========================================================================
    // Message rejection without subscription
    // =========================================================================

    public function test_message_to_unsubscribed_channel_gets_error()
    {
        $connection = $this->newActiveConnection(['websocket']);

        // Unsubscribe first
        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]));

        $connection->resetEvents();

        // Try to send a message to the channel
        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'app.someAction',
            'data' => ['test' => true],
            'channel' => 'websocket',
        ]));

        $error = collect($connection->sentData)->firstWhere('event', 'app.someAction:error');
        $this->assertNotNull($error);
        $this->assertEquals('Subscription not established', $error['data']['message']);
    }

    // =========================================================================
    // Connection close and error handling
    // =========================================================================

    public function test_on_close_removes_connection_from_channels()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $channel = $this->channelManager->find('1234', 'websocket');

        $this->assertTrue($channel->hasConnection($connection));

        $this->wsHandler->onClose($connection);

        $this->assertFalse($channel->hasConnection($connection));
    }

    public function test_on_error_does_not_close_other_connections()
    {
        $conn1 = $this->newActiveConnection(['websocket']);
        $conn2 = $this->newActiveConnection(['websocket']);

        $this->wsHandler->onError($conn1, new \Exception('Test error'));

        // Generic exceptions are ignored; only ExceptionsWebSocketException is emitted.
        $conn1->assertNotSentEvent('websocket.error');
        $conn2->assertNotSentEvent('websocket.error');

        $channel = $this->channelManager->find('1234', 'websocket');
        $this->assertTrue($channel->hasConnection($conn2));
    }

    // =========================================================================
    // Broadcasting between connections (via Channel class)
    // =========================================================================

    public function test_broadcast_to_public_channel_reaches_all_connections()
    {
        $conn1 = $this->newActiveConnection(['news']);
        $conn2 = $this->newActiveConnection(['news']);
        $conn3 = $this->newActiveConnection(['news']);

        $conn1->resetEvents();
        $conn2->resetEvents();
        $conn3->resetEvents();

        $channel = $this->channelManager->find('1234', 'news');
        $channel->broadcast('1234', (object) [
            'event' => 'news.update',
            'data' => ['title' => 'Breaking News'],
            'channel' => 'news',
        ]);

        $conn1->assertSentEvent('news.update');
        $conn2->assertSentEvent('news.update');
        $conn3->assertSentEvent('news.update');
    }

    public function test_broadcast_except_excludes_specified_socket()
    {
        $conn1 = $this->newActiveConnection(['chat']);
        $conn2 = $this->newActiveConnection(['chat']);

        $established = collect($conn1->sentData)->firstWhere('event', 'websocket.connection_established');
        $excludeSocketId = json_decode($established['data'] ?? '{}', true)['socket_id'] ?? '';

        $conn1->resetEvents();
        $conn2->resetEvents();

        $channel = $this->channelManager->find('1234', 'chat');
        $channel->broadcastToEveryoneExcept(
            (object) ['event' => 'chat.message', 'data' => ['text' => 'Hello']],
            $excludeSocketId,
            '1234'
        );

        // conn1 excluded, conn2 receives
        $conn1->assertNotSentEvent('chat.message');
        $conn2->assertSentEvent('chat.message');
    }

    // =========================================================================
    // Connection count tracking
    // =========================================================================

    public function test_channel_connection_count_tracks_subscribe_and_unsubscribe()
    {
        $conn1 = $this->newActiveConnection(['counter-channel']);
        $conn2 = $this->newActiveConnection(['counter-channel']);

        $channel = $this->channelManager->find('1234', 'counter-channel');
        $this->assertTrue($channel->hasConnection($conn1));
        $this->assertTrue($channel->hasConnection($conn2));

        // Unsubscribe one
        $this->wsHandler->onMessage($conn1, new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'counter-channel'],
        ]));

        $this->assertFalse($channel->hasConnection($conn1));
        $this->assertTrue($channel->hasConnection($conn2));
    }

    // =========================================================================
    // Message without app context
    // =========================================================================

    public function test_message_without_on_open_is_ignored()
    {
        $connection = new Mocks\Connection();
        $connection->httpRequest = new \GuzzleHttp\Psr7\Request('GET', '/?appKey=TestKey');

        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.ping',
            'data' => new \stdClass(),
        ]));

        $this->assertEmpty($connection->sentData);
    }

    // =========================================================================
    // Re-subscription after unsubscribe
    // =========================================================================

    public function test_resubscribe_after_unsubscribe_works()
    {
        $connection = $this->newActiveConnection(['websocket']);
        $channel = $this->channelManager->find('1234', 'websocket');

        // Unsubscribe
        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => ['channel' => 'websocket'],
        ]));
        $this->assertFalse($channel->hasConnection($connection));

        // Re-subscribe
        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.subscribe',
            'data' => ['channel' => 'websocket'],
        ]));
        $this->assertTrue($channel->hasConnection($connection));
    }
}
