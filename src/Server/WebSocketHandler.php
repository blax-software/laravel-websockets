<?php

namespace BlaxSoftware\LaravelWebSockets\Server;

use BlaxSoftware\LaravelWebSockets\Apps\App;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Events\ConnectionClosed;
use BlaxSoftware\LaravelWebSockets\Events\NewConnection;
use BlaxSoftware\LaravelWebSockets\Helpers;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\WebSocketException;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            $this->wsLog('warning', 'Connection rejected: server not accepting new connections');
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

                        $this->channelManager->subscribeToApp($connection->app->id);

                        $this->channelManager->connectionPonged($connection);

                        $this->wsLog('info', "[{$connection->app->id}][{$connection->socketId}] Connection established (key: {$connection->app->key})");

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
            $this->wsLog('warning', 'Message dropped: connection has no app (likely failed auth). Payload: '.Str::limit($message->getPayload(), 200));
            return;
        }

        $payload = json_decode($message->getPayload());

        if (! isset($payload->event)) {
            return;
        }

        $event = $payload->event;

        if ($this->isProtocolAction($event, 'ping')) {
            $connection->send(json_encode(['event' => 'websocket.pong']));
            $this->channelManager->connectionPonged($connection);
            return;
        }

        if ($this->isProtocolAction($event, 'subscribe')) {
            $channel = $payload->data->channel ?? null;
            if ($channel) {
                $this->channelManager->subscribeToChannel($connection, $channel, $payload->data ?? new \stdClass);
            }
            return;
        }

        if ($this->isProtocolAction($event, 'unsubscribe')) {
            $channel = $payload->data->channel ?? null;
            if ($channel) {
                $this->channelManager->unsubscribeFromChannel($connection, $channel, $payload->data ?? new \stdClass);
            }
            return;
        }

        // Client events (whisper) — must start with "client-"
        if (Str::startsWith($event, 'client-')) {
            $channel = $payload->channel ?? ($payload->data->channel ?? null);
            if ($channel) {
                $ch = $this->channelManager->find($connection->app->id, $channel);
                if ($ch) {
                    $ch->broadcastToEveryoneExcept(
                        $payload, $connection->socketId, $connection->app->id, false
                    );
                }
            }
            return;
        }
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
                    return $this->channelManager->unsubscribeFromApp($connection->app->id);
                }

                return Helpers::createFulfilledPromise(true);
            })
            ->then(function () use ($connection) {
                if (isset($connection->app)) {
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

        $appId = $connection->app->id ?? 'unknown';
        $socketId = $connection->socketId ?? 'unknown';
        $this->wsLog('error', "[{$appId}][{$socketId}] {$exception->getMessage()}");
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
                    $this->wsLog('error', "Unknown app key: '{$appKey}'. Check that PUSHER_APP_KEY in .env matches the key used by the frontend. Configured apps: ".implode(', ', array_map(fn ($a) => $a['key'] ?? 'null', config('websockets.apps', []))));
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
            'event' => 'websocket.connection_established',
            'data' => json_encode([
                'socket_id' => $connection->socketId,
                'activity_timeout' => 30,
            ]),
        ]));

        return $this;
    }

    /**
     * Check if an event matches a protocol action (e.g., subscribe, ping).
     * Matches both dot and colon delimiters for backward compatibility.
     *
     * @param  string  $event
     * @param  string  $action
     * @return bool
     */
    protected function isProtocolAction(string $event, string $action): bool
    {
        return str_ends_with($event, '.' . $action) || str_ends_with($event, ':' . $action);
    }

    /**
     * Log a WebSocket server message.
     * Uses the 'websocket' channel if configured, falls back to the default channel.
     */
    protected function wsLog(string $level, string $message): void
    {
        try {
            $channel = config('logging.channels.websocket') ? 'websocket' : config('logging.default');
            Log::channel($channel)->log($level, '[WebSocket] '.$message);
        } catch (\Throwable) {
            // Logging must never break the server
        }
    }
}
