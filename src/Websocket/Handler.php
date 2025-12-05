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
    /**
     * Track channel connections using associative arrays for O(1) lookup
     * Structure: [channel_name => [socket_id => true]]
     */
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
        if (! $this->connectionCanBeMade($connection)) {
            return $connection->close();
        }

        try {
            $this->setupConnectionAddress($connection);
            $this->verifyAppKey($connection);
            $this->verifyOrigin($connection);
            $this->limitConcurrentConnections($connection);
            $this->generateSocketId($connection);
            $this->establishConnection($connection);
            $this->initializeAppConnection($connection);
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
        if (!isset($connection->app)) {
            return;
        }

        try {
            request()->server->set('REMOTE_ADDR', $connection->remoteAddress);

            PusherMessageFactory::createForMessage(
                $message,
                $connection,
                $this->channelManager
            )->respond();

            $message = json_decode($message->getPayload(), true, 512, JSON_THROW_ON_ERROR);

            if ($this->handlePingPong($message, $connection)) {
                return;
            }

            $channel = $this->handleChannelSubscriptions($message, $connection);

            if ($this->shouldRejectMessage($channel, $connection, $message)) {
                return;
            }

            $this->authenticateConnection($connection, $channel, $message);
            \Log::channel('websocket')->info('[' . $connection->socketId . ']@' . $channel->getName() . ' | ' . json_encode($message));

            if ($this->handlePusherEvent($message, $connection)) {
                return;
            }

            $this->forkAndProcessMessage($connection, $channel, $message);
        } catch (\Throwable $e) {
            $this->handleMessageError($e);
        }
    }

    /**
     * Handle the websocket close.
     */
    public function onClose(ConnectionInterface $connection): void
    {
        $this->authenticateConnection($connection, null);

        if (isset($connection->remoteAddress)) {
            request()->server->set('REMOTE_ADDR', $connection->remoteAddress);
        }

        $this->cleanupChannelConnections($connection);
        $this->finalizeConnectionClose($connection);
    }


    protected function setupConnectionAddress(ConnectionInterface $connection): void
    {
        $connection->remoteAddress = trim(
            explode(
                ',',
                $connection->httpRequest->getHeaderLine('X-Forwarded-For')
            )[0] ?? $connection->remoteAddress
        );
        request()->server->set('REMOTE_ADDR', $connection->remoteAddress);
        Log::channel('websocket')->info('WS onOpen IP: ' . $connection->remoteAddress);
    }

    protected function initializeAppConnection(ConnectionInterface $connection): void
    {
        if (!isset($connection->app)) {
            return;
        }

        $this->channelManager->subscribeToApp($connection->app->id);
        $this->channelManager->connectionPonged($connection);

        NewConnection::dispatch(
            $connection->app->id,
            $connection->socketId
        );
    }

    protected function handlePingPong(array $message, ConnectionInterface $connection): bool
    {
        $eventLower = strtolower($message['event']);
        if ($eventLower !== 'pusher:ping' && $eventLower !== 'pusher.ping') {
            return false;
        }

        $this->channelManager->connectionPonged($connection);
        gc_collect_cycles();
        return true;
    }

    protected function shouldRejectMessage(?Channel $channel, ConnectionInterface $connection, array $message): bool
    {
        $isUnsubscribe = $message['event'] === 'pusher:unsubscribe' || $message['event'] === 'pusher.unsubscribe';

        if (!$channel?->hasConnection($connection) && !$isUnsubscribe) {
            $connection->send(json_encode([
                'event' => $message['event'] . ':error',
                'data' => [
                    'message' => 'Subscription not established',
                    'meta' => $message,
                ],
            ]));
            return true;
        }

        if (!$channel) {
            $connection->send(json_encode([
                'event' => $message['event'] . ':error',
                'data' => [
                    'message' => 'Channel not found',
                    'meta' => $message,
                ],
            ]));
            return true;
        }

        return false;
    }

    protected function handlePusherEvent(array $message, ConnectionInterface $connection): bool
    {
        if (!str_contains($message['event'], 'pusher')) {
            return false;
        }

        $connection->send(json_encode([
            'event' => $message['event'] . ':response',
            'data' => [
                'message' => 'Success',
            ],
        ]));
        return true;
    }

    protected function forkAndProcessMessage(
        ConnectionInterface $connection,
        Channel $channel,
        array $message
    ): void {
        $pid = pcntl_fork();

        if ($pid === -1) {
            Log::error('Fork error');
            return;
        }

        if ($pid === 0) {
            $this->processMessageInChild($connection, $channel, $message);
            exit(0);
        }

        $this->addDataCheckLoop($connection, $message, $pid);
    }

    protected function processMessageInChild(
        ConnectionInterface $connection,
        Channel $channel,
        array $message
    ): void {
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

            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        }
    }

    protected function handleMessageError(\Throwable $e): void
    {
        Log::channel('websocket')->error('onMessage unhandled error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    }

    protected function cleanupChannelConnections(ConnectionInterface $connection): void
    {
        $cacheUpdates = [];
        $cacheDeletes = ['ws_socket_auth_' . $connection->socketId];

        foreach ($this->channel_connections as $channel => $connections) {
            if (!isset($connections[$connection->socketId])) {
                continue;
            }

            unset($this->channel_connections[$channel][$connection->socketId]);

            if (empty($this->channel_connections[$channel])) {
                unset($this->channel_connections[$channel]);
                $cacheDeletes[] = 'ws_channel_connections_' . $channel;
                continue;
            }

            $cacheUpdates['ws_channel_connections_' . $channel] = array_keys($this->channel_connections[$channel]);
        }

        $cacheUpdates['ws_active_channels'] = array_keys($this->channel_connections);

        $authed_users = cache()->get('ws_socket_authed_users') ?? [];
        unset($authed_users[$connection->socketId]);
        $cacheUpdates['ws_socket_authed_users'] = $authed_users;

        cache()->setMultiple($cacheUpdates);
        cache()->deleteMultiple($cacheDeletes);

        \BlaxSoftware\LaravelWebSockets\Services\WebsocketService::clearUserAuthed(
            $connection->socketId
        );
    }

    protected function finalizeConnectionClose(ConnectionInterface $connection): void
    {
        $this->channelManager
            ->unsubscribeFromAllChannels($connection)
            ->then(function (bool $unsubscribed) use ($connection): void {
                if (!isset($connection->app)) {
                    return;
                }

                $this->channelManager->unsubscribeFromApp($connection->app->id);
                ConnectionClosed::dispatch($connection->app->id, $connection->socketId);
                cache()->forget('ws_connection_' . $connection->socketId);
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
        if (! isset($message['channel']) && isset($message['data']['channel'])) {
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
        $channel_name = $channel?->getName();

        if (!$channel_name || !$channel) {
            return null;
        }

        $eventLower = strtolower($message['event']);

        if ($eventLower === 'pusher.subscribe' || $eventLower === 'pusher:subscribe') {
            $this->handleSubscription($channel, $channel_name, $connection, $message);
        }

        if (str_contains($message['event'], '.unsubscribe')) {
            $this->handleUnsubscription($channel, $channel_name, $connection);
        }

        return $channel;
    }

    protected function handleSubscription(
        Channel $channel,
        string $channel_name,
        ConnectionInterface $connection,
        array $message
    ): void {
        if (!isset($this->channel_connections[$channel_name])) {
            $this->channel_connections[$channel_name] = [];
        }

        if (!isset($this->channel_connections[$channel_name][$connection->socketId])) {
            $this->channel_connections[$channel_name][$connection->socketId] = true;
        }

        cache()->setMultiple([
            'ws_channel_connections_' . $channel_name => array_keys($this->channel_connections[$channel_name]),
            'ws_active_channels' => array_keys($this->channel_connections)
        ]);

        if ($channel->hasConnection($connection)) {
            return;
        }

        try {
            $channel->subscribe($connection, (object) $message);
        } catch (\Throwable $e) {
            // Silently handle subscription errors
        }
    }

    protected function handleUnsubscription(
        Channel $channel,
        string $channel_name,
        ConnectionInterface $connection
    ): void {
        if (isset($this->channel_connections[$channel_name][$connection->socketId])) {
            unset($this->channel_connections[$channel_name][$connection->socketId]);
        }

        if (empty($this->channel_connections[$channel_name])) {
            unset($this->channel_connections[$channel_name]);
            cache()->forget('ws_channel_connections_' . $channel_name);
            cache()->forever('ws_active_channels', array_keys($this->channel_connections));
        } else {
            cache()->setMultiple([
                'ws_channel_connections_' . $channel_name => array_keys($this->channel_connections[$channel_name]),
                'ws_active_channels' => array_keys($this->channel_connections)
            ]);
        }

        $channel->unsubscribe($connection);
    }

    protected function setRequest($message, $connection)
    {
        foreach (request()->keys() as $key) {
            request()->offsetUnset($key);
        }

        request()->merge($message['data'] ?? []);
    }

    protected function authenticateConnection(
        ConnectionInterface $connection,
        PrivateChannel|Channel|PresenceChannel|null $channel,
        $message = []
    ) {
        $this->loadCachedAuth($connection, $channel);
        $this->ensureUserIsSet($connection, $channel);
        $this->updateAuthState($connection);
        $this->cacheAuthenticatedUser($connection);
        $this->scheduleLogout();
    }

    protected function loadCachedAuth(ConnectionInterface $connection, $channel): void
    {
        if (isset($connection->auth)) {
            return;
        }

        if (!$connection->socketId) {
            return;
        }

        $cached_auth = cache()->get('socket_' . $connection->socketId);
        if (!$cached_auth || !isset($cached_auth['type'])) {
            return;
        }

        $connection->user = $cached_auth['type']::find($cached_auth['id']);

        if ($channel) {
            $channel->saveConnection($connection);
        }
    }

    protected function ensureUserIsSet(ConnectionInterface $connection, $channel): void
    {
        if (isset($connection->user) && $connection->user) {
            return;
        }

        $connection->user = false;
        if ($channel) {
            $channel->saveConnection($connection);
        }
    }

    protected function updateAuthState(ConnectionInterface $connection): void
    {
        $connection->user
            ? Auth::login($connection->user)
            : Auth::logout();
    }

    protected function cacheAuthenticatedUser(ConnectionInterface $connection): void
    {
        if (!Auth::user()) {
            return;
        }

        /** @var \App\Models\User */
        $user = Auth::user();
        $user->refresh();

        cache()->forever('ws_socket_auth_' . $connection->socketId, $user);

        $authed_users = cache()->get('ws_socket_authed_users') ?? [];
        $authed_users[$connection->socketId] = $user->id;
        cache()->forever('ws_socket_authed_users', $authed_users);

        \BlaxSoftware\LaravelWebSockets\Services\WebsocketService::setUserAuthed(
            $connection->socketId,
            $user
        );
    }

    protected function scheduleLogout(): void
    {
        $this->channelManager->loop->futureTick(function () {
            Auth::logout();
        });
    }

    private function addDataCheckLoop(
        $connection,
        $message,
        $pid,
        $optional = false,
        $iteration = false
    ) {
        $pid = $this->preparePid($pid, $iteration);
        $pidcache_start = 'dedicated_start_' . $pid;
        cache()->put($pidcache_start, microtime(true), 100);

        $this->channelManager->loop->addPeriodicTimer(0.01, function ($timer) use (
            $pidcache_start,
            $message,
            $pid,
            $connection,
            $optional,
            $iteration
        ) {
            $this->checkDataLoopIteration(
                $timer,
                $pidcache_start,
                $message,
                $pid,
                $connection,
                $optional,
                $iteration
            );

            pcntl_waitpid(-1, $status, WNOHANG);
        });
    }

    protected function preparePid($pid, $iteration): string
    {
        $pid = explode('_', $pid . '')[0];

        if ($iteration >= 0 && $iteration !== false) {
            $pid .= '_' . $iteration;
        }

        return $pid;
    }

    protected function checkDataLoopIteration(
        $timer,
        string $pidcache_start,
        array $message,
        string $pid,
        $connection,
        bool $optional,
        $iteration
    ): void {
        $pidcache_data = 'dedicated_data_' . $pid;
        $pidcache_done = 'dedicated_data_' . $pid . '_done';
        $pidcache_complete = 'dedicated_data_' . $pid . '_complete';

        if ($this->handleTimeout($timer, $pidcache_start, $pidcache_complete, $message, $connection, $optional)) {
            return;
        }

        if (!cache()->has($pidcache_done)) {
            return;
        }

        $this->scheduleNextIteration($connection, $message, $pid, $iteration);
        $this->processAndSendData($connection, $pidcache_data);
        $this->channelManager->loop->cancelTimer($timer);
    }

    protected function handleTimeout(
        $timer,
        string $pidcache_start,
        string $pidcache_complete,
        array $message,
        $connection,
        bool $optional
    ): bool {
        if (!cache()->has($pidcache_start)) {
            return false;
        }

        $diff = microtime(true) - ((int) cache()->get($pidcache_start));
        if ($diff <= 60) {
            return false;
        }

        if (!$optional) {
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
        return true;
    }

    protected function scheduleNextIteration($connection, array $message, string $pid, $iteration): void
    {
        $nextIteration = ($iteration === false) ? 0 : $iteration + 1;
        $this->addDataCheckLoop($connection, $message, $pid, true, $nextIteration);
    }

    protected function processAndSendData($connection, string $pidcache_data): void
    {
        $sending = cache()->get($pidcache_data);
        $bm = json_decode($sending, true);

        if (isset($bm['broadcast']) && $bm['broadcast']) {
            $this->broadcast(
                $connection->app->id,
                $bm['data'] ?? null,
                $bm['event'] ?? null,
                $bm['channel'] ?? null,
                $bm['including_self'] ?? false,
                $connection
            );
            return;
        }

        if (isset($bm['whisper']) && $bm['whisper']) {
            $this->whisper(
                $connection->app->id,
                $bm['data'] ?? null,
                $bm['event'] ?? null,
                $bm['socket_ids'] ?? [],
                $bm['channel'] ?? null,
            );
            return;
        }

        $connection->send($sending);
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

        $socketIdLookup = array_flip($socketIds);
        foreach ($channel->getConnections() as $channel_conection) {
            if (isset($socketIdLookup[$channel_conection->socketId])) {
                $channel_conection->send(json_encode($p));
            }
        }
    }
}
