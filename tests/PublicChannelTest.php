<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

use Ratchet\ConnectionInterface;

class PublicChannelTest extends TestCase
{
    public function test_connect_to_public_channel()
    {
        $connection = $this->newActiveConnection(['public-channel']);

        $this->channelManager
            ->getGlobalConnectionsCount('1234', 'public-channel')
            ->then(function ($total) {
                $this->assertEquals(1, $total);
            });

        $connection->assertSentEvent(
            'websocket.connection_established',
            [
                'data' => json_encode([
                    'socket_id' => $connection->socketId,
                    'activity_timeout' => 30,
                ]),
            ],
        );

        $connection->assertSentEvent(
            'websocket_internal.subscription_succeeded',
            ['channel' => 'public-channel']
        );
    }

    public function test_unsubscribe_from_public_channel()
    {
        $connection = $this->newActiveConnection(['public-channel']);

        $this->channelManager
            ->getGlobalConnectionsCount('1234', 'public-channel')
            ->then(function ($total) {
                $this->assertEquals(1, $total);
            });

        $message = new Mocks\Message([
            'event' => 'websocket.unsubscribe',
            'data' => [
                'channel' => 'public-channel',
            ],
        ]);

        $this->wsHandler->onMessage($connection, $message);

        $this->channelManager
            ->getGlobalConnectionsCount('1234', 'public-channel')
            ->then(function ($total) {
                $this->assertEquals(0, $total);
            });
    }

    public function test_can_whisper_to_public_channel()
    {
        $this->app['config']->set('websockets.apps.0.enable_client_messages', true);

        $rick = $this->newActiveConnection(['public-channel']);
        $morty = $this->newActiveConnection(['public-channel']);

        $message = new Mocks\Message([
            'event' => 'client-test-whisper',
            'data' => [],
            'channel' => 'public-channel',
        ]);

        $this->wsHandler->onMessage($rick, $message);

        $rick->assertNotSentEvent('client-test-whisper');
        $morty->assertSentEvent('client-test-whisper', ['data' => [], 'channel' => 'public-channel']);
    }

    public function test_cannot_whisper_to_public_channel_if_having_whispering_disabled()
    {
        $rick = $this->newActiveConnection(['public-channel']);
        $morty = $this->newActiveConnection(['public-channel']);

        $message = new Mocks\Message([
            'event' => 'client-test-whisper',
            'data' => [],
            'channel' => 'public-channel',
        ]);

        $this->wsHandler->onMessage($rick, $message);

        $rick->assertNotSentEvent('client-test-whisper');
        $morty->assertNotSentEvent('client-test-whisper');
    }

    public function test_local_connections_for_public_channels()
    {
        $this->newActiveConnection(['public-channel']);
        $this->newActiveConnection(['public-channel-2']);

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

    public function test_events_are_processed_by_on_message_on_public_channels()
    {
        $this->runOnlyOnRedisReplication();

        $connection = $this->newActiveConnection(['public-channel']);

        $message = new Mocks\Message([
            'appId' => '1234',
            'serverId' => 'different_server_id',
            'event' => 'some-event',
            'data' => [
                'channel' => 'public-channel',
                'test' => 'yes',
            ],
        ]);

        $this->channelManager->onMessage(
            $this->channelManager->getRedisKey('1234', 'public-channel'),
            $message->getPayload()
        );

        // The message does not contain appId and serverId anymore.
        $message = new Mocks\Message([
            'event' => 'some-event',
            'data' => [
                'channel' => 'public-channel',
                'test' => 'yes',
            ],
        ]);

        $connection->assertSentEvent('some-event', $message->getPayloadAsArray());
    }

    public function test_events_get_replicated_across_connections_for_public_channels()
    {
        $this->runOnlyOnRedisReplication();

        $connection = $this->newActiveConnection(['public-channel']);
        $receiver = $this->newActiveConnection(['public-channel']);

        $message = new Mocks\Message([
            'appId' => '1234',
            'serverId' => $this->channelManager->getServerId(),
            'event' => 'some-event',
            'data' => [
                'channel' => 'public-channel',
                'test' => 'yes',
            ],
            'socketId' => $connection->socketId,
        ]);

        $channel = $this->channelManager->find('1234', 'public-channel');

        $channel->broadcastToEveryoneExcept(
            $message->getPayloadAsObject(), $connection->socketId, '1234', true
        );

        $receiver->assertSentEvent('some-event', $message->getPayloadAsArray());

        $this->getSubscribeClient()
            ->assertNothingDispatched();

        $this->getPublishClient()
            ->assertCalledWithArgs('publish', [
                $this->channelManager->getRedisKey('1234', 'public-channel'),
                $message->getPayload(),
            ]);
    }
}
