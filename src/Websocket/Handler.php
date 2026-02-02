<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\Apps\App;
use BlaxSoftware\LaravelWebSockets\Cache\IpcCache;
use BlaxSoftware\LaravelWebSockets\Channels\Channel;
use BlaxSoftware\LaravelWebSockets\Channels\PresenceChannel;
use BlaxSoftware\LaravelWebSockets\Channels\PrivateChannel;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Events\ConnectionClosed;
use BlaxSoftware\LaravelWebSockets\Events\NewConnection;
use BlaxSoftware\LaravelWebSockets\Exceptions\WebSocketException;
use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use BlaxSoftware\LaravelWebSockets\Websocket\MockConnectionSocketPair;
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
     * Whether to use event-driven socket pair IPC (true) or polling (false)
     * Socket pairs are instant but require sockets extension
     */
    private bool $useSocketPairIpc;

    /**
     * Track channel connections using associative arrays for O(1) lookup
     * Structure: [channel_name => [socket_id => true]]
     */
    protected array $channel_connections = [];

    /**
     * Cache write buffer for batching operations
     * Reduces file I/O when multiple rapid requests occur
     */
    protected array $cacheWriteBuffer = [];
    protected array $cacheDeleteBuffer = [];
    protected bool $cacheBufferScheduled = false;

    /**
     * Pre-encoded static JSON responses for performance
     * Encoding once at startup is faster than encoding every time
     */
    private static string $PONG_RESPONSE = '{"event":"pusher.pong"}';

    /**
     * GC collection counter - only collect every N pings
     */
    private int $gcCounter = 0;
    private const GC_INTERVAL = 100;

    /**
     * Whether hot reload is enabled (cached for performance)
     */
    private static ?bool $hotReload = null;

    /**
     * Initialize a new handler.
     */
    public function __construct(
        protected ChannelManager $channelManager
    ) {
        // Use socket pair IPC if available (instant), otherwise fall back to polling
        $this->useSocketPairIpc = SocketPairIpc::isSupported();
    }

    /**
     * Handle incoming WebSocket message with optimized fast path for ping/pong
     */
    public function onMessage(
        ConnectionInterface $connection,
        MessageInterface $message
    ): void {
        if (!isset($connection->app)) {
            return;
        }

        // FAST PATH: Check for ping before any heavy processing
        // Use raw string comparison on payload to avoid JSON decode overhead
        $payload = $message->getPayload();

        // Quick ping detection using strpos (faster than json_decode + array access)
        if ($this->tryHandlePingFast($payload, $connection)) {
            return;
        }

        // SLOW PATH: Full message processing
        try {
            $this->processFullMessage($connection, $message, $payload);
        } catch (\Throwable $e) {
            $this->handleMessageError($e);
        }
    }

    /**
     * Fast path for ping/pong - avoids JSON decode, object creation, promises
     * Target: < 1ms processing time
     */
    private function tryHandlePingFast(string $payload, ConnectionInterface $connection): bool
    {
        // Quick string check - if doesn't contain "ping", skip fast path
        // strpos is O(n) but very fast for short strings
        if (strpos($payload, 'ping') === false) {
            return false;
        }

        // Now do minimal JSON decode to confirm it's a ping
        $data = json_decode($payload, true);
        if ($data === null) {
            return false;
        }

        $event = $data['event'] ?? '';

        // Direct string comparison (faster than strtolower + comparison)
        if ($event !== 'pusher:ping' && $event !== 'pusher.ping') {
            return false;
        }

        // Update connection timestamp directly on connection object (no promise chain)
        $connection->lastPongedAt = time();

        // Send pre-encoded pong response immediately
        $connection->send(self::$PONG_RESPONSE);

        // Periodic GC instead of every ping
        if (++$this->gcCounter >= self::GC_INTERVAL) {
            $this->gcCounter = 0;
            gc_collect_cycles();
        }

        return true;
    }

    /**
     * Debug ping latency - call this to measure server-side processing time
     * Add to onMessage: $start = hrtime(true); ... $this->logPingLatency($start);
     */
    protected function logPingLatency(int $startNs): void
    {
        $elapsed = (hrtime(true) - $startNs) / 1_000_000; // Convert to ms
        if ($elapsed > 1.0) {
            Log::channel('websocket')->warning('Slow ping: ' . round($elapsed, 2) . 'ms');
        }
    }

    /**
     * Full message processing for non-ping messages
     */
    private function processFullMessage(
        ConnectionInterface $connection,
        MessageInterface $message,
        string $payload
    ): void {
        // Set remote address once (moved from per-message to reduce overhead)
        if (isset($connection->remoteAddress)) {
            request()->server->set('REMOTE_ADDR', $connection->remoteAddress);
        }

        // Decode message (we already have payload string)
        $messageArray = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        // Handle pusher protocol messages (subscribe, unsubscribe, etc.)
        $this->handlePusherProtocolMessage($message, $connection, $messageArray);

        $channel = $this->handleChannelSubscriptions($messageArray, $connection);

        if ($this->shouldRejectMessage($channel, $connection, $messageArray)) {
            return;
        }

        $this->authenticateConnection($connection, $channel, $messageArray);

        // Only log in debug mode to reduce I/O
        if (config('app.debug')) {
            Log::channel('websocket')->debug('[' . $connection->socketId . ']@' . $channel->getName() . ' | ' . $payload);
        }

        if ($this->handlePusherEvent($messageArray, $connection)) {
            return;
        }

        $this->forkAndProcessMessage($connection, $channel, $messageArray);
    }

    /**
     * Handle pusher protocol messages (formerly in PusherMessageFactory)
     * Inlined for performance - avoids object creation
     */
    private function handlePusherProtocolMessage(
        MessageInterface $message,
        ConnectionInterface $connection,
        array $messageArray
    ): void {
        $event = $messageArray['event'] ?? '';

        // Fast check - most messages don't start with 'pusher' or 'client-'
        $firstChar = $event[0] ?? '';
        if ($firstChar !== 'p' && $firstChar !== 'c') {
            return;
        }

        // Check for client- messages
        if (strpos($event, 'client-') === 0) {
            if (!$connection->app->clientMessagesEnabled) {
                return;
            }

            $channelName = $messageArray['channel'] ?? null;
            if (!$channelName) {
                return;
            }

            $channel = $this->channelManager->find($connection->app->id, $channelName);
            if ($channel) {
                $channel->broadcastToEveryoneExcept(
                    (object) $messageArray,
                    $connection->socketId,
                    $connection->app->id
                );
            }
            return;
        }

        // Check for pusher: or pusher. messages (subscribe/unsubscribe handled elsewhere)
        // This is handled by handleChannelSubscriptions for subscribe/unsubscribe
    }

    public function onOpen(ConnectionInterface $connection): void
    {
        if (!$this->connectionCanBeMade($connection)) {
            $connection->close();
            return;
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

        // Initialize lastPongedAt with unix timestamp (faster than Carbon)
        $connection->lastPongedAt = time();

        $this->channelManager->subscribeToApp($connection->app->id);

        NewConnection::dispatch(
            $connection->app->id,
            $connection->socketId
        );
    }

    protected function shouldRejectMessage(?Channel $channel, ConnectionInterface $connection, array $message): bool
    {
        $event = $message['event'] ?? '';
        $isUnsubscribe = $event === 'pusher:unsubscribe' || $event === 'pusher.unsubscribe';

        if (!$channel?->hasConnection($connection) && !$isUnsubscribe) {
            $connection->send(json_encode([
                'event' => $event . ':error',
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
        if ($this->useSocketPairIpc) {
            $this->forkWithSocketPair($connection, $channel, $message);
        } else {
            $this->forkWithPolling($connection, $channel, $message);
        }
    }

    /**
     * Check if hot reload mode is enabled
     */
    protected static function isHotReload(): bool
    {
        if (self::$hotReload === null) {
            self::$hotReload = (bool) config('websockets.hot_reload', false);
        }
        return self::$hotReload;
    }

    /**
     * Hot reload: Clear all caches in child process for fresh code loading
     * This allows Models, Resources, Services, and everything else to be reloaded
     * without restarting the WebSocket server.
     *
     * Only called when websockets.hot_reload is enabled.
     */
    protected function hotReloadChild(): void
    {
        if (!self::isHotReload()) {
            return;
        }

        // 1. Clear OPcache - forces PHP to recompile files from disk
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // 2. Clear Laravel's compiled services and config cache in container
        $container = \Illuminate\Container\Container::getInstance();

        // 3. Flush resolved instances - forces fresh instantiation
        //    This clears all singleton instances so they get rebuilt
        $container->forgetScopedInstances();

        // 4. Clear config repository cache (forces fresh config reads)
        //    Re-read all config files from disk
        try {
            /** @var \Illuminate\Config\Repository $config */
            $config = $container->make('config');

            // Get the path to config files
            $configPath = base_path('config');

            if (is_dir($configPath)) {
                $files = glob($configPath . '/*.php');
                foreach ($files as $file) {
                    $key = basename($file, '.php');
                    // Invalidate opcache for this config file
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($file, true);
                    }
                    // Force re-require the config file
                    $freshConfig = require $file;
                    $config->set($key, $freshConfig);
                }
            }
        } catch (\Throwable $e) {
            // Config refresh failed, continue anyway
            Log::channel('websocket')->debug('Hot reload config refresh failed: ' . $e->getMessage());
        }

        // 5. Clear view cache (if views are being used in responses)
        try {
            if ($container->bound('view')) {
                $container->forgetInstance('view');
            }
        } catch (\Throwable $e) {
            // View refresh failed, continue anyway
        }

        // 6. Clear route cache (if routes are dynamically resolved)
        try {
            if ($container->bound('router')) {
                $container->forgetInstance('router');
            }
        } catch (\Throwable $e) {
            // Router refresh failed, continue anyway
        }

        // 7. Clear translation cache
        try {
            if ($container->bound('translator')) {
                $container->forgetInstance('translator');
            }
        } catch (\Throwable $e) {
            // Translator refresh failed, continue anyway
        }

        // 8. Clear validation factory (for custom rules)
        try {
            if ($container->bound('validator')) {
                $container->forgetInstance('validator');
            }
        } catch (\Throwable $e) {
            // Validator refresh failed, continue anyway
        }

        // 9. Clear event dispatcher cache (for fresh event/listener bindings)
        try {
            if ($container->bound('events')) {
                $container->forgetInstance('events');
            }
        } catch (\Throwable $e) {
            // Events refresh failed, continue anyway
        }

        // 10. Clear WebSocket ControllerResolver cache for fresh controller loading
        ControllerResolver::clearCache();

        Log::channel('websocket')->debug('Hot reload: caches cleared in child process');
    }

    /**
     * Fork with event-driven socket pair IPC (no polling!)
     * Parent is notified INSTANTLY when child sends data
     */
    protected function forkWithSocketPair(
        ConnectionInterface $connection,
        Channel $channel,
        array $message
    ): void {
        // Create socket pair BEFORE fork
        $ipc = SocketPairIpc::create($this->channelManager->loop);

        $pid = pcntl_fork();

        if ($pid === -1) {
            Log::error('Fork error');
            return;
        }

        if ($pid === 0) {
            // === CHILD PROCESS ===
            $ipc->setupChild();

            // Hot reload: clear all caches for fresh code loading (only in dev mode)
            $this->hotReloadChild();

            try {
                // Lazy DB reconnect: disconnect now, reconnect only when first query runs
                // This saves ~5-15ms for methods that don't use the database
                DB::disconnect();

                $this->setRequest($message, $connection);

                // Create mock that sends via socket pair
                $mock = new MockConnectionSocketPair($connection, $ipc);

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
                // Send error via socket pair
                $ipc->sendToParent(json_encode([
                    'event' => $message['event'] . ':error',
                    'data' => ['message' => $e->getMessage()],
                ]));

                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }
            }

            $ipc->closeChild();
            exit(0);
        }

        // === PARENT PROCESS ===
        // Setup event-driven reading - NO POLLING!
        $startTime = microtime(true);

        $ipc->setupParent(
            // onData callback - called INSTANTLY when child sends
            function ($data) use ($connection, $message, $startTime) {
                $this->handleChildData($connection, $message, $data);

                // Log latency for debugging
                $elapsed = (microtime(true) - $startTime) * 1000;
                if ($elapsed > 10) {
                    Log::channel('websocket')->debug('IPC latency: ' . round($elapsed, 2) . 'ms');
                }
            },
            // onClose callback - child process ended
            function () {
                // Cleanup zombie process
                pcntl_waitpid(-1, $status, WNOHANG);
            }
        );
    }

    /**
     * Handle data received from child via socket pair
     */
    protected function handleChildData(ConnectionInterface $connection, array $message, $data): void
    {
        if (!$data) {
            return;
        }

        // If it's already a string (JSON), try to parse for broadcast/whisper
        if (is_string($data)) {
            $bm = json_decode($data, true);

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

            // Regular response
            $connection->send($data);
        }
    }

    /**
     * Fork with polling-based IPC (fallback when socket pairs unavailable)
     */
    protected function forkWithPolling(
        ConnectionInterface $connection,
        Channel $channel,
        array $message
    ): void {
        // Generate unique request ID BEFORE forking to avoid race conditions
        $requestId = uniqid('req_', true) . '_' . bin2hex(random_bytes(4));

        $pid = pcntl_fork();

        if ($pid === -1) {
            Log::error('Fork error');
            return;
        }

        if ($pid === 0) {
            $this->processMessageInChild($connection, $channel, $message, $requestId);
            exit(0);
        }

        $this->addDataCheckLoop($connection, $message, $requestId);
    }

    protected function processMessageInChild(
        ConnectionInterface $connection,
        Channel $channel,
        array $message,
        string $requestId
    ): void {
        // Hot reload: clear all caches for fresh code loading (only in dev mode)
        $this->hotReloadChild();

        try {
            // Lazy DB reconnect: disconnect now, reconnect only when first query runs
            // This saves ~5-15ms for methods that don't use the database
            DB::disconnect();

            $this->setRequest($message, $connection);
            $mock = new MockConnection($connection, $requestId);

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
        $socketId = $connection->socketId;

        foreach ($this->channel_connections as $channel => $connections) {
            if (!isset($connections[$socketId])) {
                continue;
            }

            unset($this->channel_connections[$channel][$socketId]);

            if (empty($this->channel_connections[$channel])) {
                unset($this->channel_connections[$channel]);
                $cacheDeletes[] = 'ws_channel_connections_' . $channel;
                continue;
            }

            // Pre-compute array_keys once per channel
            $cacheUpdates['ws_channel_connections_' . $channel] = array_keys($this->channel_connections[$channel]);
        }

        // Pre-compute active channels once
        $activeChannels = array_keys($this->channel_connections);
        $cacheUpdates['ws_active_channels'] = $activeChannels;

        // Batch read authed_users - we'll update it in the same batch
        $authed_users = cache()->get('ws_socket_authed_users') ?? [];
        unset($authed_users[$socketId]);
        $cacheUpdates['ws_socket_authed_users'] = $authed_users;

        // Single batched write and delete operation - MASSIVE latency improvement
        if (!empty($cacheUpdates)) {
            cache()->setMultiple($cacheUpdates);
        }
        if (!empty($cacheDeletes)) {
            cache()->deleteMultiple($cacheDeletes);
        }

        // Note: Removed redundant WebsocketService::clearUserAuthed() call
        // as we already handle all cache operations above in a single batch
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
        $socketId = $connection->socketId;

        if (!isset($this->channel_connections[$channel_name])) {
            $this->channel_connections[$channel_name] = [];
        }

        if (!isset($this->channel_connections[$channel_name][$socketId])) {
            $this->channel_connections[$channel_name][$socketId] = true;

            // Only update cache if connection was actually added (avoid redundant writes)
            // Pre-compute array_keys once for both updates
            $channelSockets = array_keys($this->channel_connections[$channel_name]);
            $activeChannels = array_keys($this->channel_connections);

            // Buffer these writes - they can be batched with other subscriptions
            $this->bufferCacheWrite('ws_channel_connections_' . $channel_name, $channelSockets);
            $this->bufferCacheWrite('ws_active_channels', $activeChannels);
        }

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
        $socketId = $connection->socketId;

        if (isset($this->channel_connections[$channel_name][$socketId])) {
            unset($this->channel_connections[$channel_name][$socketId]);

            // Pre-compute active channels once
            $activeChannels = array_keys($this->channel_connections);

            if (empty($this->channel_connections[$channel_name])) {
                unset($this->channel_connections[$channel_name]);

                // Buffer delete and update - can be batched
                $this->bufferCacheDelete('ws_channel_connections_' . $channel_name);
                $this->bufferCacheWrite('ws_active_channels', $activeChannels);
            } else {
                // Pre-compute channel sockets once
                $channelSockets = array_keys($this->channel_connections[$channel_name]);

                // Buffer these writes
                $this->bufferCacheWrite('ws_channel_connections_' . $channel_name, $channelSockets);
                $this->bufferCacheWrite('ws_active_channels', $activeChannels);
            }
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

        $socketId = $connection->socketId;

        // Batch all auth cache operations into a single read + single write
        $authed_users = cache()->get('ws_socket_authed_users') ?? [];
        $authed_users[$socketId] = $user->id;

        // Single batched cache write - reduces 3 operations to 1
        cache()->setMultiple([
            'ws_socket_auth_' . $socketId => $user,
            'ws_socket_authed_users' => $authed_users
        ]);

        // Note: Removed redundant WebsocketService::setUserAuthed() call
        // as we already handle all cache operations above in a single batch
    }

    protected function scheduleLogout(): void
    {
        $this->channelManager->loop->futureTick(function () {
            Auth::logout();
        });
    }

    /**
     * Add cache operation to write buffer for batching
     */
    protected function bufferCacheWrite(string $key, $value): void
    {
        $this->cacheWriteBuffer[$key] = $value;
        $this->scheduleCacheFlush();
    }

    /**
     * Add cache deletion to buffer for batching
     */
    protected function bufferCacheDelete(string $key): void
    {
        $this->cacheDeleteBuffer[] = $key;
        unset($this->cacheWriteBuffer[$key]); // Remove from write buffer if exists
        $this->scheduleCacheFlush();
    }

    /**
     * Schedule cache flush on next event loop tick
     * Multiple rapid requests will be batched into single I/O operation
     */
    protected function scheduleCacheFlush(): void
    {
        if ($this->cacheBufferScheduled) {
            return;
        }

        $this->cacheBufferScheduled = true;

        $this->channelManager->loop->futureTick(function () {
            $this->flushCacheBuffer();
        });
    }

    /**
     * Flush cache buffer - performs all pending operations in single batch
     * This is the key optimization: N operations -> 2 I/O calls (1 write, 1 delete)
     */
    protected function flushCacheBuffer(): void
    {
        if (!empty($this->cacheWriteBuffer)) {
            cache()->setMultiple($this->cacheWriteBuffer);
            $this->cacheWriteBuffer = [];
        }

        if (!empty($this->cacheDeleteBuffer)) {
            cache()->deleteMultiple(array_unique($this->cacheDeleteBuffer));
            $this->cacheDeleteBuffer = [];
        }

        $this->cacheBufferScheduled = false;
    }

    /**
     * Force immediate cache flush (use for critical operations)
     */
    protected function flushCacheBufferImmediate(): void
    {
        $this->flushCacheBuffer();
        $this->cacheBufferScheduled = false;
    }

    /**
     * IPC polling interval in seconds.
     * Lower = faster response, higher CPU usage.
     * 0.001 = 1ms, 0.002 = 2ms, 0.01 = 10ms
     */
    private const IPC_POLL_INTERVAL = 0.002; // 2ms - good balance of speed and CPU

    private function addDataCheckLoop(
        $connection,
        $message,
        string $requestId,
        $optional = false,
        int $iteration = 0
    ) {
        $iterationKey = $requestId . ($iteration > 0 ? '_' . $iteration : '');
        $cacheKeyStart = 'dedicated_start_' . $iterationKey;
        IpcCache::put($cacheKeyStart, microtime(true), 100);

        $this->channelManager->loop->addPeriodicTimer(self::IPC_POLL_INTERVAL, function ($timer) use (
            $cacheKeyStart,
            $iterationKey,
            $message,
            $requestId,
            $connection,
            $optional,
            $iteration
        ) {
            $this->checkDataLoopIteration(
                $timer,
                $cacheKeyStart,
                $message,
                $iterationKey,
                $requestId,
                $connection,
                $optional,
                $iteration
            );

            pcntl_waitpid(-1, $status, WNOHANG);
        });
    }

    protected function checkDataLoopIteration(
        $timer,
        string $cacheKeyStart,
        array $message,
        string $iterationKey,
        string $requestId,
        $connection,
        bool $optional,
        int $iteration
    ): void {
        $cacheKeyData = 'dedicated_data_' . $iterationKey;
        $cacheKeyDone = 'dedicated_data_' . $iterationKey . '_done';
        $cacheKeyComplete = 'dedicated_data_' . $iterationKey . '_complete';

        if ($this->handleTimeout($timer, $cacheKeyStart, $cacheKeyComplete, $message, $connection, $optional)) {
            return;
        }

        if (!IpcCache::has($cacheKeyDone)) {
            return;
        }

        // Clean up cache entries for this iteration before processing
        // This prevents memory leaks and stale data issues
        $this->cleanupIterationCache($iterationKey);

        $this->scheduleNextIteration($connection, $message, $requestId, $iteration);
        $this->processAndSendData($connection, $cacheKeyData);
        $this->channelManager->loop->cancelTimer($timer);
    }

    /**
     * Clean up cache entries for a completed iteration
     */
    protected function cleanupIterationCache(string $iterationKey): void
    {
        $keysToDelete = [
            'dedicated_start_' . $iterationKey,
            'dedicated_data_' . $iterationKey . '_done',
            // Note: We don't delete 'dedicated_data_' here as we need it for processAndSendData
            // It will expire naturally after 60 seconds
        ];

        IpcCache::forgetMultiple($keysToDelete);
    }

    protected function handleTimeout(
        $timer,
        string $cacheKeyStart,
        string $cacheKeyComplete,
        array $message,
        $connection,
        bool $optional
    ): bool {
        $startTime = IpcCache::get($cacheKeyStart);
        if ($startTime === null) {
            return false;
        }

        $diff = microtime(true) - ((float) $startTime);
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
        IpcCache::put($cacheKeyComplete, true, 360);
        return true;
    }

    protected function scheduleNextIteration($connection, array $message, string $requestId, int $iteration): void
    {
        $nextIteration = $iteration + 1;
        $this->addDataCheckLoop($connection, $message, $requestId, true, $nextIteration);
    }

    protected function processAndSendData($connection, string $cacheKeyData): void
    {
        $sending = IpcCache::get($cacheKeyData);

        // Clean up the data cache key immediately after reading
        IpcCache::forget($cacheKeyData);

        if (!$sending) {
            return;
        }

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
        ?string $channelName = null
    ): void {
        $p = [
            'event' => ($event ?? $event),
            'data' => $payload,
            'channel' => $channelName,
        ];

        $socketIdLookup = array_flip($socketIds);
        $encoded = json_encode($p);
        $sentTo = [];

        // Search ALL connections across ALL channels to find target socket IDs
        // This is necessary because whisper targets specific sockets regardless of channel
        $this->channelManager->getLocalConnections()->then(function ($connections) use ($socketIdLookup, $encoded, &$sentTo) {
            foreach ($connections as $connection) {
                // Skip if already sent to this socket (can appear in multiple channels)
                if (isset($sentTo[$connection->socketId])) {
                    continue;
                }

                if (isset($socketIdLookup[$connection->socketId])) {
                    $connection->send($encoded);
                    $sentTo[$connection->socketId] = true;
                }
            }
        });
    }
}
