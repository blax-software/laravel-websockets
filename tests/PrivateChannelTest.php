<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

use BlaxSoftware\LaravelWebSockets\Server\Exceptions\InvalidSignature;
use Ratchet\ConnectionInterface;

class PrivateChannelTest extends TestCase
{
    public function test_connect_to_private_channel_with_invalid_signature()
    {
        $connection = $this->newConnection();

        $message = new Mocks\Message([
            'event' => 'websocket.subscribe',
            'data' => [
                'auth' => 'invalid',
                'channel' => 'private-channel',
            ],
        ]);

        $this->wsHandler->onOpen($connection);
        $this->wsHandler->onMessage($connection, $message);

        // Invalid signature should be silently rejected — no subscription_succeeded sent
        $connection->assertNotSentEvent('websocket_internal.subscription_succeeded');
    }

    public function test_connect_to_private_channel_with_valid_signature()
    {
        $connection = $this->newConnection();

        $this->wsHandler->onOpen($connection);

        $message = new Mocks\SignedMessage([
            'event' => 'websocket.subscribe',
            'data' => [
                'channel' => 'private-channel',
            ],
        ], $connection, 'private-channel');

        $this->wsHandler->onMessage($connection, $message);

        $connection->assertSentEvent('websocket_internal.subscription_succeeded', [
            'channel' => 'private-channel',
        ]);

        $this->channelManager
            ->getGlobalConnectionsCount('1234', 'private-channel')
            ->then(function ($total) {
                $this->assertEquals(1, $total);
            });
    }

    public function test_unsubscribe_from_private_channel()
    {
        $connection = $this->newPrivateConnection('private-channel');

        $this->channelManager
            ->getGlobalConnectionsCount('1234', 'private-channel')
            ->then(function ($total) {
                $this->assertEquals(1, $total);
            });

        $message = new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => [
                'channel' => 'private-channel',
            ],
        ]);

        $this->wsHandler->onMessage($connection, $message);

        $this->channelManager
            ->getGlobalConnectionsCount('1234', 'private-channel')
            ->then(function ($total) {
                $this->assertEquals(0, $total);
            });
    }

    public function test_can_whisper_to_private_channel()
    {
        $this->app['config']->set('websockets.apps.0.enable_client_messages', true);

        $rick = $this->newPrivateConnection('private-channel');
        $morty = $this->newPrivateConnection('private-channel');

        $message = new Mocks\Message([
            'event' => 'client-test-whisper',
            'data' => [],
            'channel' => 'private-channel',
        ]);

        $this->wsHandler->onMessage($rick, $message);

        $rick->assertNotSentEvent('client-test-whisper');
        $morty->assertSentEvent('client-test-whisper', ['data' => [], 'channel' => 'private-channel']);
    }

    public function test_cannot_whisper_to_public_channel_if_having_whispering_disabled()
    {
        $rick = $this->newPrivateConnection('private-channel');
        $morty = $this->newPrivateConnection('private-channel');

        $message = new Mocks\Message([
            'event' => 'client-test-whisper',
            'data' => [],
            'channel' => 'private-channel',
        ]);

        $this->wsHandler->onMessage($rick, $message);

        $rick->assertNotSentEvent('client-test-whisper');
        $morty->assertNotSentEvent('client-test-whisper');
    }

    public function test_local_connections_for_private_channels()
    {
        $this->newPrivateConnection('private-channel');
        $this->newPrivateConnection('private-channel-2');

        $this->channelManager
            ->getLocalConnections()
            ->then(function ($connections) {
                $this->assertCount(2, $connections);

                foreach ($connections as $connection) {
                    $this->assertInstanceOf(
                        ConnectionInterface::class, $connection
                    );
                }
            });
    }

    public function test_events_are_processed_by_on_message_on_private_channels()
    {
        $this->runOnlyOnRedisReplication();

        $connection = $this->newPrivateConnection('private-channel');

        $message = new Mocks\SignedMessage([
            'appId' => '1234',
            'serverId' => 'different_server_id',
            'event' => 'some-event',
            'data' => [
                'channel' => 'private-channel',
                'test' => 'yes',
            ],
        ], $connection, 'private-channel');

        $this->channelManager->onMessage(
            $this->channelManager->getRedisKey('1234', 'private-channel'),
            $message->getPayload()
        );

        // The message does not contain appId and serverId anymore.
        $message = new Mocks\SignedMessage([
            'event' => 'some-event',
            'data' => [
                'channel' => 'private-channel',
                'test' => 'yes',
            ],
        ], $connection, 'private-channel');

        $connection->assertSentEvent('some-event', $message->getPayloadAsArray());
    }

    public function test_events_get_replicated_across_connections_for_private_channels()
    {
        $this->runOnlyOnRedisReplication();

        $connection = $this->newPrivateConnection('private-channel');
        $receiver = $this->newPrivateConnection('private-channel');

        $message = new Mocks\SignedMessage([
            'appId' => '1234',
            'serverId' => $this->channelManager->getServerId(),
            'event' => 'some-event',
            'data' => [
                'channel' => 'private-channel',
                'test' => 'yes',
            ],
            'socketId' => $connection->socketId,
        ], $connection, 'private-channel');

        $channel = $this->channelManager->find('1234', 'private-channel');

        $channel->broadcastToEveryoneExcept(
            $message->getPayloadAsObject(), $connection->socketId, '1234', true
        );

        $receiver->assertSentEvent('some-event', $message->getPayloadAsArray());

        $this->getSubscribeClient()
            ->assertNothingDispatched();

        $this->getPublishClient()
            ->assertCalledWithArgs('publish', [
                $this->channelManager->getRedisKey('1234', 'private-channel'),
                $message->getPayload(),
            ]);
    }

}
