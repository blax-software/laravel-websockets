<?php

namespace BlaxSoftware\LaravelWebSockets\Server;

use BlaxSoftware\LaravelWebSockets\Apps\App;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\DashboardLogger;
use BlaxSoftware\LaravelWebSockets\Events\ConnectionClosed;
use BlaxSoftware\LaravelWebSockets\Events\NewConnection;
use BlaxSoftware\LaravelWebSockets\Events\WebSocketMessageReceived;
use BlaxSoftware\LaravelWebSockets\Facades\StatisticsCollector;
use BlaxSoftware\LaravelWebSockets\Helpers;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\WebSocketException;
use Exception;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class WebSocketHandler implements MessageComponentInterface
{
    /**
     * The channel manager.
     *
     * @var ChannelManager
     */
    protected $channelManager;

    /**
     * Initialize a new handler.
     *
     * @param  \BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager  $channelManager
     * @return void
     */
    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }

    /**
     * Handle the socket opening.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return void
     */
    public function onOpen(ConnectionInterface $connection)
    {
        if (! $this->connectionCanBeMade($connection)) {
            return $connection->close();
        }

        $this->verifyAppKey($connection)
            ->then(function () use ($connection) {
                try {
                    $this->verifyOrigin($connection)
                        ->limitConcurrentConnections($connection)
                        ->generateSocketId($connection)
                        ->establishConnection($connection);

                    if (isset($connection->app)) {
                        /** @var \GuzzleHttp\Psr7\Request $request */
                        $request = $connection->httpRequest;

                        if ($connection->app->statisticsEnabled) {
                            StatisticsCollector::connection($connection->app->id);
                        }

                        $this->channelManager->subscribeToApp($connection->app->id);

                        $this->channelManager->connectionPonged($connection);

                        DashboardLogger::log($connection->app->id, DashboardLogger::TYPE_CONNECTED, [
                            'origin' => "{$request->getUri()->getScheme()}://{$request->getUri()->getHost()}",
                            'socketId' => $connection->socketId,
                        ]);

                        NewConnection::dispatch($connection->app->id, $connection->socketId);
                    }
                } catch (WebSocketException $exception) {
                    $this->onError($connection, $exception);
                }
            }, function ($exception) use ($connection) {
                $this->onError($connection, $exception);
            });
    }

    /**
     * Handle the incoming message.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  \Ratchet\RFC6455\Messaging\MessageInterface  $message
     * @return void
     */
    public function onMessage(ConnectionInterface $connection, MessageInterface $message)
    {
        if (! isset($connection->app)) {
            return;
        }

        Messages\PusherMessageFactory::createForMessage(
            $message, $connection, $this->channelManager
        )->respond();

        if ($connection->app->statisticsEnabled) {
            StatisticsCollector::webSocketMessage($connection->app->id);
        }

        WebSocketMessageReceived::dispatch(
            $connection->app->id,
            $connection->socketId,
            $message
        );
    }

    /**
     * Handle the websocket close.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return PromiseInterface
     */
    public function onClose(ConnectionInterface $connection)
    {
        return $this->channelManager
            ->unsubscribeFromAllChannels($connection)
            ->then(function (bool $unsubscribed) use ($connection) {
                if (isset($connection->app)) {
                    if ($connection->app->statisticsEnabled) {
                        StatisticsCollector::disconnection($connection->app->id);
                    }

                    return $this->channelManager->unsubscribeFromApp($connection->app->id);
                }

                return Helpers::createFulfilledPromise(true);
            })
            ->then(function () use ($connection) {
                if (isset($connection->app)) {
                    DashboardLogger::log($connection->app->id, DashboardLogger::TYPE_DISCONNECTED, [
                        'socketId' => $connection->socketId,
                    ]);

                    ConnectionClosed::dispatch($connection->app->id, $connection->socketId);
                }
            });
    }

    /**
     * Handle the websocket errors.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  WebSocketException  $exception
     * @return void
     */
    public function onError(ConnectionInterface $connection, Exception $exception)
    {
        if ($exception instanceof Exceptions\WebSocketException) {
            $connection->send(json_encode(
                $exception->getPayload()
            ));
        }
    }

    /**
     * Check if the connection can be made for the
     * current server instance.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return bool
     */
    protected function connectionCanBeMade(ConnectionInterface $connection): bool
    {
        return $this->channelManager->acceptsNewConnections();
    }

    /**
     * Verify the app key validity.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return PromiseInterface
     */
    protected function verifyAppKey(ConnectionInterface $connection): PromiseInterface
    {
        $deferred = new Deferred();

        $query = QueryParameters::create($connection->httpRequest);

        $appKey = $query->get('appKey');

        App::findByKey($appKey)
            ->then(function ($app) use ($appKey, $connection, $deferred) {
                if (! $app) {
                    $deferred->reject(new Exceptions\UnknownAppKey($appKey));
                }

                $connection->app = $app;

                $deferred->resolve();
            });

        return $deferred->promise();
    }

    /**
     * Verify the origin.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return $this
     */
    protected function verifyOrigin(ConnectionInterface $connection)
    {
        if (! $connection->app->allowedOrigins) {
            return $this;
        }

        $header = (string) ($connection->httpRequest->getHeader('Origin')[0] ?? null);

        $origin = parse_url($header, PHP_URL_HOST) ?: $header;

        if (! $header || ! in_array($origin, $connection->app->allowedOrigins)) {
            throw new Exceptions\OriginNotAllowed($connection->app->key);
        }

        return $this;
    }

    /**
     * Limit the connections count by the app.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return $this
     */
    protected function limitConcurrentConnections(ConnectionInterface $connection)
    {
        if (! is_null($capacity = $connection->app->capacity)) {
            $this->channelManager
                ->getGlobalConnectionsCount($connection->app->id)
                ->then(function ($connectionsCount) use ($capacity, $connection) {
                    if ($connectionsCount >= $capacity) {
                        $exception = new Exceptions\ConnectionsOverCapacity;

                        $payload = json_encode($exception->getPayload());

                        tap($connection)->send($payload)->close();
                    }
                });
        }

        return $this;
    }

    /**
     * Create a socket id.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return $this
     */
    protected function generateSocketId(ConnectionInterface $connection)
    {
        $socketId = sprintf('%d.%d', random_int(1, 1000000000), random_int(1, 1000000000));

        $connection->socketId = $socketId;

        return $this;
    }

    /**
     * Establish connection with the client.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return $this
     */
    protected function establishConnection(ConnectionInterface $connection)
    {
        $connection->send(json_encode([
            'event' => 'pusher.connection_established',
            'data' => json_encode([
                'socket_id' => $connection->socketId,
                'activity_timeout' => 30,
            ]),
        ]));

        return $this;
    }
}
