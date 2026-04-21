<?php

namespace BlaxSoftware\LaravelWebSockets\Test;

use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;
use React\Promise\Deferred;

class OpenPresenceChannelFeatureTest extends TestCase
{
    private const CHANNEL = 'openpresence-support-test';

    public function test_openpresence_presence_changed_payload_tracks_socket_counts(): void
    {
        $first = $this->newSocketConnection('10.0.0.1');
        $firstSocketId = $this->extractSocketId($first);
        $this->subscribeToChannel($first, self::CHANNEL);

        $subscribed = $this->findLastEvent($first->sentData, 'presence.subscription_succeeded');
        $this->assertNotNull($subscribed);
        $baselineCount = (int) ($subscribed['data']['total_count'] ?? 0);
        $this->assertEquals($baselineCount, count($subscribed['data']['sockets'] ?? []));

        $first->resetEvents();

        $second = $this->newSocketConnection('10.0.0.2');
        $secondSocketId = $this->extractSocketId($second);
        $this->subscribeToChannel($second, self::CHANNEL);

        $joined = $this->findLastEvent($second->sentData, 'presence.changed');
        $this->assertNotNull($joined);
        $this->assertEquals($secondSocketId, $joined['data']['joined']);
        $joinedCount = (int) ($joined['data']['total_count'] ?? 0);
        $this->assertEquals($joinedCount, count($joined['data']['sockets'] ?? []));
        $this->assertGreaterThanOrEqual($baselineCount, $joinedCount);
    }

    public function test_handler_keeps_ws_channel_connections_cache_in_sync_for_openpresence_channels(): void
    {
        WebsocketService::resetAllTracking();

        $first = $this->newSocketConnection('10.0.0.1');
        $second = $this->newSocketConnection('10.0.0.2');
        $firstSocketId = $this->extractSocketId($first);
        $secondSocketId = $this->extractSocketId($second);

        $this->subscribeToChannel($first, self::CHANNEL);
        $this->flushFutureTicks();

        $connections = WebsocketService::getChannelConnections(self::CHANNEL);
        $this->assertEquals([$firstSocketId], $connections);

        $this->subscribeToChannel($second, self::CHANNEL);
        $this->flushFutureTicks();

        $connections = WebsocketService::getChannelConnections(self::CHANNEL);
        sort($connections);
        $expected = [$firstSocketId, $secondSocketId];
        sort($expected);
        $this->assertEquals($expected, $connections);

        $this->wsHandler->onClose($second);
        $this->flushFutureTicks();

        $connections = WebsocketService::getChannelConnections(self::CHANNEL);
        $this->assertEquals([$firstSocketId], $connections);

        $this->wsHandler->onClose($first);
        $this->flushFutureTicks();

        $this->assertEmpty(WebsocketService::getChannelConnections(self::CHANNEL));
    }

    private function newSocketConnection(string $remoteAddress): Mocks\Connection
    {
        $connection = $this->newConnection();
        /** @var mixed $rawConnection */
        $rawConnection = $connection;
        $rawConnection->remoteAddress = $remoteAddress;

        $this->wsHandler->onOpen($connection);

        return $connection;
    }

    private function extractSocketId(Mocks\Connection $connection): string
    {
        $event = $this->findLastEvent($connection->sentData, 'websocket.connection_established');
        $this->assertNotNull($event);

        $payload = json_decode((string) ($event['data'] ?? '{}'), true);
        $socketId = (string) ($payload['socket_id'] ?? '');
        $this->assertNotSame('', $socketId);

        return $socketId;
    }

    private function subscribeToChannel(Mocks\Connection $connection, string $channel): void
    {
        $this->wsHandler->onMessage($connection, new Mocks\Message([
            'event' => 'websocket.subscribe',
            'data' => [
                'channel' => $channel,
            ],
        ]));
    }

    private function flushFutureTicks(int $ticks = 2): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $deferred = new Deferred();
            $this->loop->futureTick(function () use ($deferred): void {
                $deferred->resolve(true);
            });
            $this->await($deferred->promise());
        }
    }

    private function findLastEvent(array $events, string $name): ?array
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['event'] ?? null) === $name) {
                return $events[$i];
            }
        }

        return null;
    }
}
