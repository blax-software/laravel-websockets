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

        $this->channelManager
            ->getLocalConnections()
            ->then(function ($connections) use ($connection) {
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

                $memberAddedPayload = [
                    'event' => 'presence.member_added',
                    'channel' => $this->getName(),
                    'data' => [
                        'socket' => $connection->socketId, // added socket
                        'total_count' => collect($connections)
                            ->filter(fn($conn) => ($conn->remoteAddress && $conn->remoteAddress != '127.0.0.1'))
                            ->count(),
                    ],
                ];


                if ($connection->remoteAddress && $connection->remoteAddress != '127.0.0.1') {
                    $this->broadcastToEveryoneExcept(
                        (object) $memberAddedPayload,
                        $connection->socketId,
                        $connection->app->id,
                        false
                    );
                }
            });

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


        return $this->channelManager
            ->getLocalConnections()
            ->then(function ($connections) use ($connection) {
                $memberRemovedPayload = [
                    'event' => 'presence:member_removed',
                    'channel' => $this->getName(),
                    'data' => [
                        'socket' => $connection->socketId,
                        'total_count' => collect($connections)
                            ->filter(fn($conn) => ($conn->remoteAddress && $conn->remoteAddress != '127.0.0.1'))
                            ->count(),
                    ],
                ];

                if ($connection->remoteAddress && $connection->remoteAddress != '127.0.0.1') {
                    $this->broadcastToEveryoneExcept(
                        (object) $memberRemovedPayload,
                        $connection->socketId,
                        $connection->app->id,
                        false
                    );
                }
            })
            ->then(function () use ($truth) {
                return $truth;
            });
    }
}
