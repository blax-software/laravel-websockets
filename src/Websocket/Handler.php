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
use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use BlaxSoftware\LaravelWebSockets\Websocket\MockConnectionSocketPair;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\ConnectionsOverCapacity;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\OriginNotAllowed;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\UnknownAppKey;
use BlaxSoftware\LaravelWebSockets\Server\Exceptions\WebSocketException as ExceptionsWebSocketException;
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
    private static string $PONG_RESPONSE = '{"event":"websocket.pong"}';

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
     * Whether debug mode is enabled (cached to avoid container resolution per message)
     */
    private static ?bool $debug = null;

    /**
     * Track active child processes to limit concurrent DB connections.
     * Each forked child may open its own MySQL connection, so we must
     * cap concurrency to avoid exhausting MySQL's max_connections.
     */
    private int $activeChildCount = 0;

    /**
     * Maximum concurrent child processes (and thus DB connections).
     * Configurable via websockets.max_concurrent_children config.
     * Default 50 leaves headroom for PHP-FPM, queue workers, etc.
     */
    private int $maxConcurrentChildren = 50;

    /**
     * Queue of deferred messages waiting for a child slot.
     * Each entry is [ConnectionInterface, Channel, array $message].
     */
    private array $deferredMessages = [];

    /**
     * Initialize a new handler.
     */
    public function __construct(
        protected ChannelManager $channelManager
    ) {
        $this->maxConcurrentChildren = (int) config('websockets.max_concurrent_children', 50);
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

        // Match any prefix with . or : delimiter followed by 'ping'
        if (!self::isProtocolAction($event, 'ping')) {
            return false;
        }

        // ALWAYS update local pong timestamp first — this is the ground truth
        // that proves the connection is alive. Without this, if the Redis
        // connectionPonged() call below fails, parent::connectionPonged()
        // (chained after Redis) never runs, and the local
        // removeObsoleteConnections() also considers the connection stale.
        $connection->lastPongedAt = time();

        // Also update Redis sorted set score so the Redis-based
        // removeObsoleteConnections() doesn't consider this connection stale.
        // This is async and does not block the pong response.
        $this->channelManager->connectionPonged($connection)
            ->then(null, function (\Throwable $e) use ($connection) {
                // Redis pong update failed — the local lastPongedAt is still fresh,
                // so the local cleanup won't remove this connection. However the
                // Redis-based cleanup may still see a stale score. This is handled
                // by cross-checking local connection liveness in removeObsoleteConnections().
                Log::channel('websocket')->error('connectionPonged failed for ' . ($connection->socketId ?? '?') . ': ' . $e->getMessage());
            });

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
        // Any received message proves the client is alive — update local pong timestamp.
        // This is a safety net for LocalChannelManager::removeObsoleteConnections().
        // The primary Redis score update happens in tryHandlePingFast() via connectionPonged().
        $connection->lastPongedAt = time();

        // Set remote address once (moved from per-message to reduce overhead)
        if (isset($connection->remoteAddress)) {
            request()->server->set('REMOTE_ADDR', $connection->remoteAddress);
        }

        // Decode message (we already have payload string)
        $messageArray = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        // Handle protocol messages (client-* broadcasts)
        $this->handleProtocolMessage($message, $connection, $messageArray);

        $channel = $this->handleChannelSubscriptions($messageArray, $connection);

        if ($this->shouldRejectMessage($channel, $connection, $messageArray)) {
            return;
        }

        $this->authenticateConnection($connection, $channel, $messageArray);

        // Only log in debug mode to reduce I/O
        if (self::$debug ??= (bool) config('app.debug')) {
            Log::channel('websocket')->debug('[' . $connection->socketId . ']@' . $channel->getName() . ' | ' . $payload);
        }

        if ($this->handleProtocolEvent($messageArray, $connection)) {
            return;
        }

        $this->forkWithSocketPair($connection, $channel, $messageArray);
    }

    /**
     * Handle pusher protocol messages (formerly in PusherMessageFactory)
     * Inlined for performance - avoids object creation
     */
    private function handleProtocolMessage(
        MessageInterface $message,
        ConnectionInterface $connection,
        array $messageArray
    ): void {
        $event = $messageArray['event'] ?? '';

        // Check for client- broadcast messages
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
        }
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

        // Clean up per-connection session from Redis
        cache()->forget('ws_session_' . $connection->socketId);
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

        // Register connection pong in both local memory and Redis sorted set.
        // The Redis score is checked by removeObsoleteConnections() every 10s.
        // Without this, the connection wouldn't have a Redis score until the
        // first channel subscription, leaving a window for stale removal.
        $this->channelManager->connectionPonged($connection);

        $this->channelManager->subscribeToApp($connection->app->id);

        NewConnection::dispatch(
            $connection->app->id,
            $connection->socketId
        );
    }

    protected function shouldRejectMessage(?Channel $channel, ConnectionInterface $connection, array $message): bool
    {
        $event = $message['event'] ?? '';
        $isUnsubscribe = self::isProtocolAction($event, 'unsubscribe');

        if (!$channel?->hasConnection($connection) && !$isUnsubscribe) {
            // The connection may have been removed from Channel::$connections by
            // removeObsoleteConnections() (Redis stale score race) while the socket
            // is still alive. If Handler::$channel_connections still tracks it, the
            // connection was legitimately subscribed — silently re-subscribe instead
            // of returning an error to the client.
            $channelName = $channel?->getName();
            if ($channelName && isset($this->channel_connections[$channelName][$connection->socketId])) {
                // Re-add to Channel::$connections transparently
                $channel->saveConnection($connection);
                Log::channel('websocket')->info('Auto-resubscribed connection ' . $connection->socketId . ' to channel ' . $channelName);
                return false; // Allow the message to proceed
            }

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

    /**
     * Handle protocol-level events (subscribe, unsubscribe, ping, etc.).
     * These are events with a known action after the delimiter (e.g. websocket.subscribe).
     * Sends an immediate :response acknowledgement and short-circuits forkWithSocketPair.
     */
    protected function handleProtocolEvent(array $message, ConnectionInterface $connection): bool
    {
        $event = $message['event'] ?? '';

        if (!self::isProtocolAction($event, 'subscribe') && !self::isProtocolAction($event, 'unsubscribe')) {
            return false;
        }

        $connection->send(json_encode([
            'event' => $event . ':response',
            'data' => [
                'message' => 'Success',
            ],
        ]));
        return true;
    }

    /**
     * Check if an event name ends with a known protocol action.
     * Matches any prefix with either . or : as delimiter.
     *
     * Examples that match isProtocolAction($event, 'subscribe'):
     *   websocket.subscribe, pusher:subscribe, my.prefix.subscribe
     *
     * Examples that do NOT match:
     *   admin.unsubscribeUserStatus (does not end with .subscribe or :subscribe)
     */
    protected static function isProtocolAction(string $event, string $action): bool
    {
        return str_ends_with($event, '.' . $action)
            || str_ends_with($event, ':' . $action);
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
     *
     * Includes a concurrency limiter: if max_concurrent_children is reached,
     * the message is queued and processed when a child slot frees up.
     * This prevents exhausting MySQL's max_connections under load.
     */
    protected function forkWithSocketPair(
        ConnectionInterface $connection,
        Channel $channel,
        array $message
    ): void {
        // Check concurrency limit before forking
        if ($this->activeChildCount >= $this->maxConcurrentChildren) {
            // Queue the message for later processing
            $this->deferredMessages[] = [$connection, $channel, $message];

            if (count($this->deferredMessages) === 1) {
                // Log only on first deferral to avoid log spam
                Log::channel('websocket')->warning('Fork concurrency limit reached (' . $this->maxConcurrentChildren . '), queueing message', [
                    'active_children' => $this->activeChildCount,
                    'queued' => count($this->deferredMessages),
                ]);
            }
            return;
        }

        $this->activeChildCount++;

        // Create socket pair BEFORE fork
        $ipc = SocketPairIpc::create($this->channelManager->loop);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->activeChildCount--;
            Log::error('Fork error');
            $this->processDeferredMessages();
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

                // Purge inherited Redis/cache connections from parent process.
                // After fork(), child inherits parent's Redis socket fd — using it
                // would corrupt parent's protocol state. Purging forces fresh
                // connections on next cache() call (predis connects lazily).
                app()->forgetInstance('cache');
                app()->forgetInstance('cache.store');
                app()->forgetInstance('redis');

                // Configure DB reconnect-on-lost-connection for this child.
                // If MySQL returns "Too many connections" or "server has gone away",
                // Laravel will retry the query once after reconnecting.
                try {
                    $dbConfig = config('database.connections.' . config('database.default'), []);
                    if (empty($dbConfig['retry_on_connection_loss'] ?? null)) {
                        config(['database.connections.' . config('database.default') . '.retry_on_connection_loss' => true]);
                    }
                } catch (\Throwable $e) {
                    // Non-critical, continue without retry config
                }

                $this->setRequest($message, $connection);

                // Set up per-connection session (backed by Redis)
                $session = new ConnectionSession($connection->socketId);
                app()->instance('ws.session', $session);

                // Create mock that sends via socket pair
                $mock = new MockConnectionSocketPair($connection, $ipc);

                $this->executeControllerWithDbResilience(
                    $mock,
                    $channel,
                    $message,
                    $this->channelManager
                );

                \Illuminate\Container\Container::getInstance()
                    ->make(\Illuminate\Support\Defer\DeferredCallbackCollection::class)
                    ->invokeWhen(fn($callback) => true);

                // Persist session changes to Redis before exit
                $session->save();
            } catch (Exception $e) {
                // Send error via socket pair
                $ipc->sendToParent(json_encode([
                    'event' => $message['event'] . ':error',
                    'data' => ['message' => $e->getMessage()],
                ]));

                // Log DB connection failures specifically for monitoring
                if ($this->isDbConnectionError($e)) {
                    Log::channel('websocket')->error('DB connection failure in child process', [
                        'error' => $e->getMessage(),
                        'event' => $message['event'] ?? 'unknown',
                    ]);
                }

                try {
                    if (app()->bound('sentry')) {
                        app('sentry')->captureException($e);
                    }
                } catch (\Throwable $sentryError) {
                    // Sentry capture failed (possibly also a DB issue), ignore
                }
            }

            // Flush Sentry before the child exits so captured events are actually sent.
            // Without this, events from report()/captureException() may be lost because
            // the child calls exit(0) before the async transport can dispatch them.
            try {
                if (app()->bound('sentry')) {
                    app('sentry')->flush();
                }
            } catch (\Throwable $e) {
                // Sentry flush failed, continue with cleanup
            }

            // Explicitly close the MySQL connection before exit.
            // Relying on exit(0) to close the FD is not instant — MySQL may keep
            // the connection slot occupied until TCP cleanup completes.
            // Under burst load this causes "Too many connections" errors.
            try {
                DB::disconnect();
            } catch (\Throwable $e) {
                // Disconnect failed, OS will clean up on exit
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

                // Free up a child slot and process any queued messages
                $this->activeChildCount = max(0, $this->activeChildCount - 1);
                $this->processDeferredMessages();
            }
        );
    }

    /**
     * Process queued messages that were deferred due to concurrency limits.
     * Called when a child process exits, freeing a slot.
     */
    protected function processDeferredMessages(): void
    {
        while (!empty($this->deferredMessages) && $this->activeChildCount < $this->maxConcurrentChildren) {
            [$connection, $channel, $message] = array_shift($this->deferredMessages);

            // Verify the connection is still open before processing
            if (!isset($connection->socketId) || !isset($connection->app)) {
                continue;
            }

            $this->forkWithSocketPair($connection, $channel, $message);
        }

        if (!empty($this->deferredMessages)) {
            Log::channel('websocket')->info('Deferred message queue: ' . count($this->deferredMessages) . ' remaining');
        }
    }

    /**
     * Execute the controller with DB connection resilience.
     * If an attempt fails with a DB connection error (e.g., "Too many connections",
     * "server has gone away"), retries with exponential backoff up to 2 times.
     */
    protected function executeControllerWithDbResilience(
        $mock,
        Channel $channel,
        array $message,
        ChannelManager $channelManager
    ): void {
        $maxRetries = 2;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                if ($attempt > 0) {
                    // Force a completely fresh DB connection before retry
                    try {
                        DB::disconnect();
                        DB::reconnect();
                    } catch (\Throwable $reconnectError) {
                        Log::channel('websocket')->error('DB reconnect failed on retry attempt ' . $attempt, [
                            'error' => $reconnectError->getMessage(),
                        ]);
                        throw $lastException;
                    }
                }

                Controller::controll_message($mock, $channel, $message, $channelManager);
                return; // Success
            } catch (\Throwable $e) {
                if (!$this->isDbConnectionError($e)) {
                    throw $e;
                }

                $lastException = $e;

                if ($attempt < $maxRetries) {
                    // Exponential backoff: 500ms, 1500ms
                    $backoffMs = 500 * ($attempt + 1);
                    Log::channel('websocket')->warning('DB connection error, retry ' . ($attempt + 1) . '/' . $maxRetries . ' after ' . $backoffMs . 'ms', [
                        'error' => $e->getMessage(),
                        'event' => $message['event'] ?? 'unknown',
                    ]);
                    usleep($backoffMs * 1000);
                }
            }
        }

        // All retries exhausted
        Log::channel('websocket')->error('DB connection error persisted after ' . $maxRetries . ' retries', [
            'error' => $lastException?->getMessage(),
            'event' => $message['event'] ?? 'unknown',
        ]);
        throw $lastException;
    }

    /**
     * Check if an exception is a DB connection error (too many connections, gone away, etc.)
     */
    protected function isDbConnectionError(\Throwable $e): bool
    {
        $message = $e->getMessage();
        $previous = $e->getPrevious();
        $fullMessage = $message . ($previous ? ' ' . $previous->getMessage() : '');

        $dbErrorPatterns = [
            'Too many connections',
            'SQLSTATE[08004]',
            'SQLSTATE[HY000] [1040]',
            'server has gone away',
            'SQLSTATE[HY000] [2006]',
            'Lost connection to MySQL',
            'SQLSTATE[HY000] [2002]',
            'Connection refused',
            'SQLSTATE[08S01]',
            'no connection to the server',
        ];

        foreach ($dbErrorPatterns as $pattern) {
            if (stripos($fullMessage, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle data received from child via socket pair
     */
    protected function handleChildData(ConnectionInterface $connection, array $message, $data): void
    {
        if (!$data) {
            return;
        }

        // Prefix-based routing: C: = connection data, B: = broadcast, W: = whisper, else regular response
        // Avoids JSON decode overhead for regular responses (most common path)
        if (str_starts_with($data, 'C:')) {
            // Connection data operation from child process.
            // Lets controllers set/clear/reset arbitrary properties on the
            // parent's in-memory connection object via IPC.
            $op = substr($data, 2);

            if ($op === 'RESET') {
                // Clear auth state
                unset($connection->authLoaded);
                $connection->user = null;

                // Clear any custom connection data that was stored via C:SET
                foreach (($connection->_connectionDataKeys ?? []) as $key => $_) {
                    unset($connection->$key);
                }
                $connection->_connectionDataKeys = [];
            } elseif (str_starts_with($op, 'SET:')) {
                // C:SET:key:json_value
                $rest = substr($op, 4);
                $pos = strpos($rest, ':');
                if ($pos !== false) {
                    $key = substr($rest, 0, $pos);
                    $value = json_decode(substr($rest, $pos + 1));
                    $connection->$key = $value;
                    $connection->_connectionDataKeys ??= [];
                    $connection->_connectionDataKeys[$key] = true;
                }
            } elseif (str_starts_with($op, 'DEL:')) {
                // C:DEL:key
                $key = substr($op, 4);
                unset($connection->$key);
                if (isset($connection->_connectionDataKeys[$key])) {
                    unset($connection->_connectionDataKeys[$key]);
                }
            }

            return;
        }

        if (str_starts_with($data, 'B:')) {
            $bm = json_decode(substr($data, 2), true);
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

        if (str_starts_with($data, 'W:')) {
            $bm = json_decode(substr($data, 2), true);
            $this->whisper(
                $connection->app->id,
                $bm['data'] ?? null,
                $bm['event'] ?? null,
                $bm['socket_ids'] ?? [],
                $bm['channel'] ?? null,
            );
            return;
        }

        // Regular response - send directly without JSON decode
        $connection->send($data);
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
            'event' => 'websocket.connection_established',
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

        $event = $message['event'];

        if (self::isProtocolAction($event, 'unsubscribe')) {
            $this->handleUnsubscription($channel, $channel_name, $connection);
        }

        if (self::isProtocolAction($event, 'subscribe')) {
            $this->handleSubscription($channel, $channel_name, $connection, $message);
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
            $channel->subscribe($connection, (object) ($message['data'] ?? []));
        } catch (\Throwable $e) {
            // Silently handle subscription errors (e.g. invalid signatures)
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
        // Fast path: auth already resolved for this connection (skips cache read + DB query + cache write)
        if (isset($connection->authLoaded)) {
            if ($connection->user) {
                Auth::login($connection->user);
            }
            $this->scheduleLogout();
            return;
        }

        $this->loadCachedAuth($connection, $channel);
        $this->ensureUserIsSet($connection, $channel);
        $this->updateAuthState($connection);
        $this->cacheAuthenticatedUser($connection);
        $this->scheduleLogout();

        $connection->authLoaded = true;
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

    public function broadcast(
        string $appId,
        mixed $payload,
        ?string $event = null,
        ?string $channel = null,
        bool $including_self = false,
        $connection = null
    ): void {
        $channel = $this->channelManager->findOrCreate($appId, $channel);

        // Pre-encode once for all connections
        $encoded = json_encode([
            'event' => $event,
            'data' => $payload,
            'channel' => $channel->getName(),
        ]);

        foreach ($channel->getConnections() as $channel_conection) {
            if ($channel_conection->socketId !== $connection->socketId) {
                $channel_conection->send($encoded);
            }
        }

        if ($including_self) {
            $connection->send($encoded);
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
