<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\Apps\App;
use BlaxSoftware\LaravelWebSockets\Channels\Channel;
use BlaxSoftware\LaravelWebSockets\Channels\PresenceChannel;
use BlaxSoftware\LaravelWebSockets\Channels\PrivateChannel;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Events\ConnectionClosed;
use BlaxSoftware\LaravelWebSockets\Events\NewConnection;
use BlaxSoftware\LaravelWebSockets\Exceptions\WebSocketException;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\ConnectionsOverCapacity;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\OriginNotAllowed;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\UnknownAppKey;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\WebSocketException as ExceptionsWebSocketException;
use BlaxSoftware\LaravelWebSockets\Server\Messages\PusherMessageFactory;
use BlaxSoftware\LaravelWebSockets\Server\QueryParameters;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\MessageComponentInterface;

class Handler implements MessageComponentInterface
{
    protected $channel_connections = [];

    /**
     * Initialize a new handler.
     *
     * @return void
     */
    public function __construct(
        protected ChannelManager $channelManager
    ) {}

    public function onOpen(ConnectionInterface $connection)
    {
        try {
            if (! $this->connectionCanBeMade($connection)) {
                return $connection->close();
            }

            // Set IP to connection
            $connection->remoteAddress = trim(
                explode(
                    ',',
                    $connection->httpRequest->getHeaderLine('X-Forwarded-For')
                )[0] ?? $connection->remoteAddress
            );
            request()->server->set('REMOTE_ADDR', $connection->remoteAddress);
            Log::channel('websocket')->info('WS onOpen IP: ' . $connection->remoteAddress);

            $this->verifyAppKey($connection);
            $this->verifyOrigin($connection);
            $this->limitConcurrentConnections($connection);
            $this->generateSocketId($connection);
            $this->establishConnection($connection);

            if (isset($connection->app)) {
                $this->channelManager->subscribeToApp($connection->app->id);
                $this->channelManager->connectionPonged($connection);

                NewConnection::dispatch(
                    $connection->app->id,
                    $connection->socketId
                );
            }
        } catch (UnknownAppKey $e) {
            Log::channel('websocket')->error('Root level error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function onMessage(
        ConnectionInterface $connection,
        MessageInterface $message
    ) {
        try {
            if (! isset($connection->app)) {
                return;
            }

            request()->server->set('REMOTE_ADDR', $connection->remoteAddress);

            PusherMessageFactory::createForMessage(
                $message,
                $connection,
                $this->channelManager
            )->respond();

            // Payload json to array
            $message = json_decode($message->getPayload(), true);

            // Cut short for ping pong
            if (
                (strtolower($message['event']) === 'pusher:ping')
                || (strtolower($message['event']) === 'pusher.ping')
            ) {
                $this->channelManager->connectionPonged($connection);
                return gc_collect_cycles();
            }

            $channel = $this->handleChannelSubscriptions($message, $connection);

            if (! optional($channel)->hasConnection($connection) && !(
                $message['event'] !== 'pusher:unsubscribe'
                && $message['event'] !== 'pusher.unsubscribe'
            )) {
                return $connection->send(json_encode([
                    'event' => $message['event'] . ':error',
                    'data' => [
                        'message' => 'Subscription not established',
                        'meta' => $message,
                    ],
                ]));
            }

            if (!$channel) {
                return $connection->send(json_encode([
                    'event' => $message['event'] . ':error',
                    'data' => [
                        'message' => 'Channel not found',
                        'meta' => $message,
                    ],
                ]));
            }

            $this->authenticateConnection($connection, $channel, $message);

            \Log::channel('websocket')->info('[' . $connection->socketId . ']@' . $channel->getName() . ' | ' . json_encode($message));

            if (strpos($message['event'], 'pusher') !== false) {
                return $connection->send(json_encode([
                    'event' => $message['event'] . ':response',
                    'data' => [
                        'message' => 'Success',
                    ],
                ]));
            }

            $pid = pcntl_fork();

            if ($pid == -1) {
                Log::error('Fork error');
            } elseif ($pid == 0) {
                try {
                    DB::disconnect();
                    DB::reconnect();

                    $this->setRequest($message, $connection);
                    $mock = new MockConnection($connection);

                    Controller::controll_message(
                        $mock,
                        $channel,
                        $message,
                        $this->channelManager
                    );

                    // Run deferred callbacks
                    \Illuminate\Container\Container::getInstance()
                        ->make(\Illuminate\Support\Defer\DeferredCallbackCollection::class)
                        ->invokeWhen(fn($callback) => true);
                } catch (Exception $e) {
                    $mock->send(json_encode([
                        'event' => $message['event'] . ':error',
                        'data' => [
                            'message' => $e->getMessage(),
                        ],
                    ]));
                }

                exit(0);
            } else {
                $this->addDataCheckLoop($connection, $message, $pid);
            }
        } catch (\Throwable $e) {
            Log::channel('websocket')->error('onMessage unhandled error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle the websocket close.
     */
    public function onClose(ConnectionInterface $connection): void
    {
        // remove connection from $channel_connections
        foreach ($this->channel_connections as $channel => $connections) {
            if (in_array($connection->socketId, $connections)) {
                $this->channel_connections[$channel] = array_diff($connections, [$connection->socketId]);
            }

            if (empty(@$this->channel_connections[$channel])) {
                unset($this->channel_connections[$channel]);
            }

            cache()->forget(
                'ws_socket_auth_' . $connection->socketId,
            );

            if (@$this->channel_connections[$channel]) {
                cache()->forever(
                    'ws_channel_connections_' . $channel,
                    @$this->channel_connections[$channel]
                );
            } else {
                cache()->forget('ws_channel_connections_' . $channel);
            }

            cache()->forever(
                'ws_active_channels',
                array_keys($this->channel_connections)
            );

            $authed_users = cache()->get('ws_socket_authed_users') ?? [];
            unset($authed_users[$connection->socketId]);
            cache()->forever('ws_socket_authed_users', $authed_users);
        }

        $this->channelManager
            ->unsubscribeFromAllChannels($connection)
            ->then(function (bool $unsubscribed) use ($connection): void {
                if (isset($connection->app)) {
                    $this->channelManager->unsubscribeFromApp($connection->app->id);

                    ConnectionClosed::dispatch($connection->app->id, $connection->socketId);

                    cache()->forget('ws_connection_' . $connection->socketId);
                }
            });
    }

    /**
     * Handle the websocket errors.
     *
     * @param  WebSocketException  $exception
     */
    public function onError(ConnectionInterface $connection, Exception $exception): void
    {
        if ($exception instanceof ExceptionsWebSocketException) {
            $connection->send(json_encode(
                $exception->getPayload()
            ));
        }
    }

    /**
     * Check if the connection can be made for the
     * current server instance.
     */
    protected function connectionCanBeMade(ConnectionInterface $connection): bool
    {
        return $this->channelManager->acceptsNewConnections();
    }

    /**
     * Verify the app key validity.
     *
     * @return $this
     */
    protected function verifyAppKey(ConnectionInterface $connection)
    {
        $query = QueryParameters::create($connection->httpRequest);

        $appKey = $query->get('appKey');

        if (! $app = App::findByKey($appKey)) {
            throw new UnknownAppKey($appKey);
        }

        $app->then(function ($app) use ($connection) {
            $connection->app = $app;
        });

        return $this;
    }

    /**
     * Verify the origin.
     *
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
            throw new OriginNotAllowed($connection->app->key);
        }

        return $this;
    }

    /**
     * Limit the connections count by the app.
     *
     * @return $this
     */
    protected function limitConcurrentConnections(ConnectionInterface $connection)
    {
        if (! is_null($capacity = $connection->app->capacity)) {
            $this->channelManager
                ->getGlobalConnectionsCount($connection->app->id)
                ->then(function ($connectionsCount) use ($capacity, $connection): void {
                    if ($connectionsCount >= $capacity) {
                        $exception = new ConnectionsOverCapacity;

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

    protected function get_connection_channel(&$connection, &$message): ?Channel
    {
        // Put channel on its place
        if (! @$message['channel'] && $message['data'] && $message['data']['channel']) {
            $message['channel'] = $message['data']['channel'];
            unset($message['data']['channel']);
        }

        $this->channelManager->findOrCreate(
            $connection->app->id,
            $message['channel']
        );

        return $this->channelManager->find(
            $connection->app->id,
            $message['channel']
        );
    }

    protected function handleChannelSubscriptions($message, $connection): ?Channel
    {
        $channel = $this->get_connection_channel($connection, $message);
        $channel_name = optional($channel)->getName();
        $socket_id = $connection->socketId;

        if (! $channel_name || ! $channel) {
            return null;
        }

        // if not in $channel_connections add it
        if (
            (strtolower($message['event']) === 'pusher.subscribe')
            || (strtolower($message['event']) === 'pusher:subscribe')
        ) {
            if (! isset($this->channel_connections[$channel_name])) {
                $this->channel_connections[$channel_name] = [];
            }

            if (! in_array($connection->socketId, $this->channel_connections[$this->get_connection_channel($connection, $message)->getName()])) {
                $this->channel_connections[$channel_name][] = $connection->socketId;
            }

            cache()->forever(
                'ws_channel_connections_' . $channel_name,
                $this->channel_connections[$channel_name]
            );

            cache()->forever(
                'ws_active_channels',
                array_keys($this->channel_connections)
            );

            if (! $channel->hasConnection($connection)) {
                try {
                    $channel->subscribe($connection, (object) $message);
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        if (strpos($message['event'], '.unsubscribe') !== false) {
            if (isset($this->channel_connections[$channel_name])) {
                $this->channel_connections[$channel_name] = array_diff($this->channel_connections[$channel_name], [$socket_id]);
            }

            if (empty($this->channel_connections[$channel_name])) {
                unset($this->channel_connections[$channel_name]);
            }

            if (@$this->channel_connections[$channel_name]) {
                cache()->forever(
                    'ws_channel_connections_' . $channel_name,
                    $this->channel_connections[$channel_name]
                );
            } else {
                cache()->forget('ws_channel_connections_' . $channel_name);
            }

            cache()->forever(
                'ws_active_channels',
                array_keys($this->channel_connections)
            );

            $channel->unsubscribe($connection);
        }

        return $channel;
    }

    protected function setRequest($message, $connection)
    {
        foreach (request()->keys() as $key) {
            request()->offsetUnset($key);
        }

        request()->merge(@$message['data'] ?? []);
    }

    protected function authenticateConnection(
        ConnectionInterface $connection,
        PrivateChannel|Channel|PresenceChannel|null $channel,
        $message
    ) {

        if (! optional($connection)->auth && $connection->socketId && cache()->get('socket_' . $connection->socketId)) {

            $cached_auth = cache()->get('socket_' . $connection->socketId);

            $connection->user = @$cached_auth['type']::find($cached_auth['id']);

            $channel->saveConnection($connection);
        }

        // Update last online of user if user
        if (! optional($connection)->user) {
            $connection->user = false;
            $channel->saveConnection($connection);
        }

        // Set auth or logout
        ($connection->user)
            ? Auth::login($connection->user)
            : Auth::logout();

        if (Auth::user()) {
            /** @var \App\Models\User */
            $user = Auth::user();
            $user->refresh();

            cache()->forever(
                'ws_socket_auth_' . $connection->socketId,
                $user,
            );


            $authed_users = cache()->get('ws_socket_authed_users') ?? [];
            $authed_users[$connection->socketId] = $user->id;
            cache()->forever('ws_socket_authed_users', $authed_users);
        }
    }

    private function addDataCheckLoop(
        $connection,
        $message,
        $pid,
        $optional = false,
        $iteration = false
    ) {
        $pid = explode('_', $pid . '')[0];

        if ($iteration >= 0 && $iteration !== false) {
            $pid .= '_' . $iteration;
        }

        // Set timeout start
        $pidcache_start = 'dedicated_start_' . $pid;
        cache()->put($pidcache_start, microtime(true), 100);

        // Periodic check for data
        $this->channelManager->loop->addPeriodicTimer(0.01, function ($timer) use (
            $pidcache_start,
            $message,
            $pid,
            $connection,
            $optional,
            $iteration
        ) {
            $pidcache_data = 'dedicated_data_' . $pid;
            $pidcache_done = 'dedicated_data_' . $pid . '_done';
            $pidcache_complete = 'dedicated_data_' . $pid . '_complete';

            if (
                cache()->has($pidcache_start)
                && ($diff = microtime(true) - ((int) cache()->get($pidcache_start))) > 60
            ) {
                if (! $optional) {
                    $connection->send(json_encode([
                        'event' => $message['event'] . ':error',
                        'data' => [
                            'message' => $message['event'] . ' timeout',
                            'diff' => $diff,
                        ],
                    ]));
                }

                $this->channelManager->loop->cancelTimer($timer);
                cache()->put($pidcache_complete, true, 360);
            }

            if (cache()->has($pidcache_done)) {
                // call self with pid + '_0' and optional
                if ($iteration === false) {
                    $this->addDataCheckLoop($connection, $message, $pid, true, 0);
                } else {
                    $this->addDataCheckLoop($connection, $message, $pid, true, $iteration + 1);
                }

                // Retrieve cached data
                $sending = @cache()->get($pidcache_data);
                $bm = json_decode($sending, true);


                // Send the data to client
                if (@$bm['broadcast']) {
                    $this->broadcast(
                        $connection->app->id,
                        $bm['data'] ?? null,
                        $bm['event'] ?? null,
                        $bm['channel'] ?? null,
                        $bm['including_self'],
                        $connection
                    );
                } elseif (@$bm['whisper']) {
                    $this->whisper(
                        $connection->app->id,
                        $bm['data'] ?? null,
                        $bm['event'] ?? null,
                        $bm['socket_ids'] ?? [],
                        $bm['channel'] ?? null,
                    );
                } else {
                    $connection->send($sending);
                }

                // Stop periodic check
                $this->channelManager->loop->cancelTimer($timer);
            }

            // Prevent zombie processes
            pcntl_waitpid(-1, $status, WNOHANG);
        });
    }

    public function broadcast(
        string $appId,
        mixed $payload,
        ?string $event = null,
        ?string $channel = null,
        bool $including_self = false,
        $connection = null
    ): void {

        $channel = $this->channelManager->findOrCreate($appId, $channel);

        $p = [
            'event' => ($event ?? $event),
            'data' => $payload,
            'channel' => $channel->getName(),
        ];

        foreach ($channel->getConnections() as $channel_conection) {
            if ($channel_conection->socketId !== $connection->socketId) {
                $channel_conection->send(json_encode($p));
            }

            if ($including_self) {
                $connection->send(json_encode($p));
            }
        }
    }

    public function whisper(
        string $appId,
        mixed $payload,
        ?string $event = null,
        array $socketIds = [],
        ?string $channel = null
    ): void {
        $channel = $this->channelManager->findOrCreate($appId, $channel);

        $p = [
            'event' => ($event ?? $event),
            'data' => $payload,
            'channel' => $channel->getName(),
        ];

        foreach ($channel->getConnections() as $channel_conection) {
            if (in_array($channel_conection->socketId, $socketIds)) {
                $channel_conection->send(json_encode($p));
            }
        }
    }
}
