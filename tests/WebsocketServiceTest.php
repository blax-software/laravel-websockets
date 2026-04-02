<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;

/**
 * Tests for WebsocketService state tracking methods.
 *
 * These methods use Laravel's cache to track WebSocket connection state
 * (authed users, active channels, connections). No running WS server needed.
 */
class WebsocketServiceTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Start clean for each test
        WebsocketService::resetAllTracking();
    }

    // =========================================================================
    // resetAllTracking
    // =========================================================================

    public function test_reset_all_tracking_clears_all_ws_cache_keys()
    {
        // Seed some data
        cache()->forever('ws_active_channels', ['websocket', 'private-user']);
        cache()->forever('ws_socket_authed_users', ['1.1' => 1]);
        cache()->forever('ws_connection_1-1', ['id' => '1.1']);

        $this->assertTrue(WebsocketService::resetAllTracking());

        $this->assertEmpty(WebsocketService::getActiveChannels());
        $this->assertEmpty(WebsocketService::getAuthedUsers());
    }

    // =========================================================================
    // User auth tracking
    // =========================================================================

    public function test_set_user_authed_stores_user_in_cache()
    {
        $user = (object) ['id' => 42];

        WebsocketService::setUserAuthed('1.123456', $user);

        $this->assertEquals([
            '1.123456' => 42,
        ], WebsocketService::getAuthedUsers());
    }

    public function test_get_auth_returns_stored_user_for_socket()
    {
        $user = (object) ['id' => 42, 'name' => 'Rick'];

        WebsocketService::setUserAuthed('1.123456', $user);

        $auth = WebsocketService::getAuth('1.123456');
        $this->assertNotNull($auth);
        $this->assertEquals(42, $auth->id);
        $this->assertEquals('Rick', $auth->name);
    }

    public function test_get_auth_returns_null_for_unknown_socket()
    {
        $this->assertNull(WebsocketService::getAuth('99.999'));
    }

    public function test_clear_user_authed_removes_user()
    {
        $user = (object) ['id' => 42];
        WebsocketService::setUserAuthed('1.123', $user);

        $this->assertCount(1, WebsocketService::getAuthedUsers());

        WebsocketService::clearUserAuthed('1.123');

        $this->assertEmpty(WebsocketService::getAuthedUsers());
        $this->assertNull(WebsocketService::getAuth('1.123'));
    }

    public function test_multiple_users_can_be_authed_simultaneously()
    {
        WebsocketService::setUserAuthed('1.100', (object) ['id' => 1]);
        WebsocketService::setUserAuthed('1.200', (object) ['id' => 2]);
        WebsocketService::setUserAuthed('1.300', (object) ['id' => 3]);

        $authed = WebsocketService::getAuthedUsers();

        $this->assertCount(3, $authed);
        $this->assertEquals(1, $authed['1.100']);
        $this->assertEquals(2, $authed['1.200']);
        $this->assertEquals(3, $authed['1.300']);
    }

    public function test_clear_one_user_does_not_affect_others()
    {
        WebsocketService::setUserAuthed('1.100', (object) ['id' => 1]);
        WebsocketService::setUserAuthed('1.200', (object) ['id' => 2]);

        WebsocketService::clearUserAuthed('1.100');

        $authed = WebsocketService::getAuthedUsers();
        $this->assertCount(1, $authed);
        $this->assertEquals(2, $authed['1.200']);
    }

    // =========================================================================
    // isUserConnected / getUserSocketIds
    // =========================================================================

    public function test_is_user_connected_returns_true_for_authed_user()
    {
        WebsocketService::setUserAuthed('1.100', (object) ['id' => 42]);

        $this->assertTrue(WebsocketService::isUserConnected(42));
    }

    public function test_is_user_connected_returns_false_for_unknown_user()
    {
        $this->assertFalse(WebsocketService::isUserConnected(999));
    }

    public function test_is_user_connected_returns_false_after_clear()
    {
        WebsocketService::setUserAuthed('1.100', (object) ['id' => 42]);
        WebsocketService::clearUserAuthed('1.100');

        $this->assertFalse(WebsocketService::isUserConnected(42));
    }

    public function test_get_user_socket_ids_returns_all_sockets_for_user()
    {
        // Same user connected from two devices
        WebsocketService::setUserAuthed('1.100', (object) ['id' => 42]);
        WebsocketService::setUserAuthed('1.200', (object) ['id' => 42]);
        WebsocketService::setUserAuthed('1.300', (object) ['id' => 99]);

        $sockets = WebsocketService::getUserSocketIds(42);

        $this->assertCount(2, $sockets);
        $this->assertContains('1.100', $sockets);
        $this->assertContains('1.200', $sockets);
    }

    public function test_get_user_socket_ids_returns_empty_for_unknown_user()
    {
        $this->assertEmpty(WebsocketService::getUserSocketIds(999));
    }

    // =========================================================================
    // Channel and connection tracking
    // =========================================================================

    public function test_get_active_channels_returns_empty_by_default()
    {
        $this->assertEmpty(WebsocketService::getActiveChannels());
    }

    public function test_get_active_channels_returns_cached_channels()
    {
        cache()->forever('ws_active_channels', ['websocket', 'private-user.1']);

        $channels = WebsocketService::getActiveChannels();

        $this->assertEquals(['websocket', 'private-user.1'], $channels);
    }

    public function test_get_channel_connections_returns_empty_for_unknown_channel()
    {
        $this->assertEmpty(WebsocketService::getChannelConnections('nonexistent'));
    }

    public function test_get_channel_connections_returns_cached_sockets()
    {
        cache()->forever('ws_channel_connections_websocket', ['1.100', '1.200', '1.300']);

        $connections = WebsocketService::getChannelConnections('websocket');

        $this->assertCount(3, $connections);
        $this->assertContains('1.100', $connections);
    }

    // =========================================================================
    // BroadcastClient availability
    // =========================================================================

    public function test_ws_available_returns_false_when_socket_file_missing()
    {
        // No Unix socket file exists in test — ws_available should be false
        $this->assertFalse(ws_available());
    }

    public function test_websocket_service_whisper_returns_false_when_unavailable()
    {
        $this->assertFalse(
            WebsocketService::whisper('event', ['data' => 'test'], ['1.100'])
        );
    }

    public function test_websocket_service_broadcast_except_returns_false_when_unavailable()
    {
        $this->assertFalse(
            WebsocketService::broadcastExcept('event', ['data' => 'test'], ['1.100'])
        );
    }
}
