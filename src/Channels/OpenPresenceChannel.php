<?php

namespace BlaxSoftware\LaravelWebSockets\Channels;

use BlaxSoftware\LaravelWebSockets\Server\Exceptions\InvalidSignature;
use Ratchet\ConnectionInterface;
use React\Promise\PromiseInterface;
use stdClass;

class OpenPresenceChannel extends Channel
{
    /**
     * Subscribe to the channel.
     *
     * @see    https://pusher.com/docs/pusher_protocol#presence-channel-events
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  \stdClass  $payload
     * @return bool
     *
     * @throws InvalidSignature
     */
    public function subscribe(ConnectionInterface $connection, stdClass $payload): bool
    {
        parent::subscribe($connection, $payload);

        $connections = $this->getConnections();

        $connection->send(json_encode([
            'event' => 'presence.subscription_succeeded',
            'channel' => $this->getName(),
            'data' => [
                'sockets' => collect($connections)
                    ->filter(fn($conn) => ($conn->remoteAddress && $conn->remoteAddress != '127.0.0.1'))
                    ->pluck('socketId')->toArray(),
                'total_count' => collect($connections)
                    ->filter(fn($conn) => ($conn->remoteAddress && $conn->remoteAddress != '127.0.0.1'))
                    ->count(),
            ],
        ]));

        $this->informPresence($connection, $connections);

        return true;
    }

    /**
     * Unsubscribe connection from the channel.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return PromiseInterface
     */
    public function unsubscribe(ConnectionInterface $connection): PromiseInterface
    {
        $truth = parent::unsubscribe($connection);

        $this->informPresence($connection, $this->getConnections(), true);

        return $truth;
    }

    public function informPresence(
        $connection,
        $connections,
        bool $isLeaving = false
    ) {
        $memberAddedPayload = [
            'event' => 'presence.changed',
            'channel' => $this->getName(),
            'data' => [
                ($isLeaving ? 'removed' : 'joined') => $connection->socketId,
                'total_count' => collect($connections)
                    ->filter(fn($conn) => ($conn->remoteAddress && $conn->remoteAddress != '127.0.0.1'))
                    ->filter(fn($conn) => $isLeaving ? $conn->socketId != $connection->socketId : true)
                    ->count(),
                'sockets' => collect($connections)
                    ->filter(fn($conn) => ($conn->remoteAddress && $conn->remoteAddress != '127.0.0.1'))
                    ->filter(fn($conn) => $isLeaving ? $conn->socketId != $connection->socketId : true)
                    ->pluck('socketId')->toArray(),
            ],
        ];

        if (!$isLeaving)
            $connection->send(json_encode($memberAddedPayload));

        if ($connection->remoteAddress && $connection->remoteAddress != '127.0.0.1') {
            $this->broadcastToEveryoneExcept(
                (object) $memberAddedPayload,
                $connection->socketId,
                $connection->app->id,
                false
            );
        }
    }
}
