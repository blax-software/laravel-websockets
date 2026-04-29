<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\ChannelManagers\LocalChannelManager;
use BlaxSoftware\LaravelWebSockets\Channels\Channel;
use BlaxSoftware\LaravelWebSockets\Channels\PresenceChannel;
use BlaxSoftware\LaravelWebSockets\Channels\PrivateChannel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Ratchet\ConnectionInterface;

class Controller
{
    protected bool $isMockConnection;

    final public function __construct(
        protected ConnectionInterface $connection,
        protected PrivateChannel|Channel|PresenceChannel|null $channel,
        protected string $event,
        protected LocalChannelManager $channelManager
    ) {
        $this->isMockConnection = $connection instanceof MockConnectionSocketPair;
    }

    /**
     * To be overridden by child classes if needed
     * Called before need_auth check
     * If return is exactly false, processing stops
     *
     * @return void
     */
    public function boot() {}

    /**
     * To be overridden by child classes if needed
     * Called after need_auth check
     * If return is exactly false, processing stops
     *
     * @return void
     */
    public function booted() {}

    /**
     * To be overridden by child classes if needed
     * Called after main function execution (even if not found)
     *
     * @return void
     */
    public function unboot(): void {}

    public static function controll_message(
        ConnectionInterface $connection,
        PrivateChannel|Channel|PresenceChannel $channel,
        array $message,
        LocalChannelManager $channelManager
    ) {
        $event = self::get_event($message);

        // Introspection: single-part event like "auth" or "websocket"
        if (count($event) === 1) {
            return self::handleIntrospection($connection, $message, $event[0]);
        }

        if (count($event) !== 2) {
            return self::send_error($connection, $message, 'Event unknown');
        }

        try {
            $eventPrefix = $event[0];
            $method = static::without_uniquifyer($event[1]);

            // Use cached controller resolver for fast lookup
            $controllerClass = ControllerResolver::resolve($eventPrefix);

            if (! $controllerClass) {
                // Fallback: an HTTP controller method tagged with the
                // #[Websocket] attribute may handle this event. The registry
                // exposes a flat event-name → callable map built by
                // reflecting attribute-tagged methods at scan time.
                //
                // IMPORTANT: registry keys are stored without the client-side
                // `[uniquifier]` segment (e.g. `flightschool.index[abc123]`),
                // so we rebuild the lookup name from the cleaned prefix +
                // cleaned method instead of using `$message['event']` raw.
                $cleanEvent = $eventPrefix . '.' . $method;
                $target = EventRegistry::resolve($cleanEvent);
                if ($target) {
                    return self::dispatchHttpAttributeTarget($connection, $message, $target);
                }

                return self::send_error($connection, $message, 'Event could not be associated');
            }

            $controller = new $controllerClass(
                $connection,
                $channel,
                $message['event'],
                $channelManager
            );

            if ($controller->boot() === false) {
                return;
            }

            if (($controller->need_auth ?? true) && ! $connection->user) {
                // Self-heal: the parent process may have a stale DB connection that
                // can't find newly created tokens. The child process has a fresh DB
                // connection (reconnected after fork), so try to authenticate here.
                $authtoken = @$message['data']['authtoken'] ?? null;
                if ($authtoken) {
                    try {
                        $resolved = self::resolveUserFromToken($authtoken);
                        if ($resolved) {
                            $connection->user = $resolved;
                            Auth::login($connection->user);
                            // Clear parent's stale auth cache so it re-authenticates
                            if ($connection instanceof MockConnectionSocketPair) {
                                $connection->clearConnectionData('authLoaded');
                            }
                        }
                    } catch (\Throwable $e) {
                        // Auth self-heal failed, fall through to Unauthorized
                    }
                }

                if (! $connection->user) {
                    $controller->error('Unauthorized');
                    $controller->unboot();
                    return;
                }
            }

            if (! method_exists($controllerClass, $method)) {
                $controller->error('Event could not be handled');
                $controller->unboot();
                return;
            }

            if ($controller->booted() === false) {
                return;
            }

            $payload = $controller->$method(
                $connection,
                @$message['data'] ?? [],
                $message['channel']
            );

            $controller->unboot();

            if ($payload === false || $payload === true) {
                return null;
            }

            $connection->send(json_encode([
                'event' => $message['event'] . ':response',
                'data' => $payload,
                'channel' => $message['channel'],
            ]));

            return $payload;
        } catch (\Throwable $e) {
            $reload = [
                'event' => @$message['event'],
                'data' => @$message['data'],
                'channel' => @$message['channel'],
                'line' => $e->getFile() . ':' . $e->getLine(),
                'stack' => $e->getTraceAsString(),
            ];
            Log::error($e->getMessage(), $reload);

            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }

            return self::send_error($connection, $message, $e->getMessage(), true);
        }
    }

    /**
     * Dispatch an event whose target is an HTTP controller method tagged
     * with the {@see \BlaxSoftware\LaravelWebSockets\Attributes\Websocket}
     * attribute (resolved via {@see EventRegistry}).
     *
     * HTTP controllers don't accept the WS-specific constructor args
     * ($connection, $channel, $event, $channelManager), so we resolve them
     * through the Laravel container and shape the call to look like a
     * normal HTTP invocation:
     *
     *  - request()->merge($data) so `request('foo')` works
     *  - method positional args resolved by name from $data
     *  - JsonResponse/Response payloads unwrapped to plain data
     *
     * Auth gating mirrors the standard flow: `needAuth` enforces an
     * authenticated $connection->user, with the same self-heal hop via
     * the auth token if the parent process didn't see it.
     *
     * @param array{class: class-string, method: string, needAuth: bool} $target
     */
    protected static function dispatchHttpAttributeTarget(
        ConnectionInterface $connection,
        array $message,
        array $target
    ) {
        if ($target['needAuth'] && ! ($connection->user ?? null)) {
            $authtoken = @$message['data']['authtoken'] ?? null;
            if ($authtoken) {
                try {
                    $resolved = self::resolveUserFromToken($authtoken);
                    if ($resolved) {
                        $connection->user = $resolved;
                        Auth::login($connection->user);
                    }
                } catch (\Throwable $e) {
                    // self-heal failed; fall through to the unauthorized branch
                }
            }

            if (! ($connection->user ?? null)) {
                return self::send_error($connection, $message, 'Unauthorized');
            }
        }

        $data = is_array($message['data'] ?? null) ? $message['data'] : [];

        // Make WS data visible to anything that calls `request()`.
        try {
            request()->merge($data);
        } catch (\Throwable $e) {
            // request() not bound — shouldn't happen in a Laravel app, ignore.
        }

        $instance = app($target['class']);
        $args = self::resolveAttributeMethodArgs($target['class'], $target['method'], $data);

        $payload = $instance->{$target['method']}(...$args);

        // Normalize Response/JsonResponse → plain data
        if ($payload instanceof \Illuminate\Http\JsonResponse) {
            $payload = $payload->getData(true);
        } elseif ($payload instanceof \Symfony\Component\HttpFoundation\Response) {
            $decoded = json_decode($payload->getContent() ?: 'null', true);
            $payload = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $payload->getContent();
        }

        $connection->send(json_encode([
            'event' => $message['event'] . ':response',
            'data' => $payload,
            'channel' => $message['channel'] ?? null,
        ]));

        return $payload;
    }

    /**
     * Reflect the target method and pull positional args by parameter name
     * from the WS payload. This mirrors how Laravel's HTTP route bindings
     * pass URL segments to controller methods (e.g. `show(string $slug)`
     * gets the `slug` value from $data['slug']).
     *
     * Falls through to default values, then null for nullable params.
     *
     * @return array<int, mixed>
     */
    protected static function resolveAttributeMethodArgs(string $class, string $method, array $data): array
    {
        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\Throwable) {
            return [];
        }

        $args = [];
        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $data)) {
                $args[] = $data[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                // Required scalar with no value provided — leave the
                // method to throw/validate so the error reaches the client.
                break;
            }
        }

        return $args;
    }

    /**
     * Resolve a user from an authtoken string. First tries the configured
     * `websockets.auth_resolver` callable; falls back to Laravel Sanctum's
     * `PersonalAccessToken::findToken()` if the class exists.
     *
     * Returns an Authenticatable user or null.
     */
    protected static function resolveUserFromToken(string $authtoken)
    {
        // 1. Configured resolver (closure or [Class, method])
        $resolver = config('websockets.auth_resolver');
        if ($resolver && is_callable($resolver)) {
            $user = $resolver($authtoken);
            if ($user) {
                return $user;
            }
        }

        // 2. Container binding (useful for class-based resolvers)
        if (app()->bound('websockets.auth_resolver')) {
            $bound = app('websockets.auth_resolver');
            if (is_callable($bound)) {
                $user = $bound($authtoken);
                if ($user) {
                    return $user;
                }
            }
        }

        // 3. Fallback to Sanctum if available (string class name to avoid
        // autoload errors when the package isn't installed)
        $sanctumClass = 'Laravel\\Sanctum\\PersonalAccessToken';
        if (class_exists($sanctumClass)) {
            $tokenRecord = $sanctumClass::findToken($authtoken);
            if ($tokenRecord?->tokenable) {
                return $tokenRecord->tokenable;
            }
        }

        return null;
    }

    final public function progress(
        mixed $payload = null,
        ?string $event = null,
        ?string $channel = null
    ): bool {
        $p = [
            'event' => ($event ?? $this->event) . ':progress',
            'data' => $payload,
            'channel' => $channel ?? $this->channel->getName(),
        ];

        // if payload only contains key "data"
        if (
            count($p) === 1
            && isset($payload['data'])
        ) {
            $p['data'] = $payload['data'];
        }

        // Pre-encode once (avoid repeated encoding)
        $encoded = json_encode($p);

        $this->connection->send($encoded);

        return true;
    }

    final public function success(
        mixed $payload = null,
        ?string $event = null,
        ?string $channel = null
    ): bool {
        $p = [
            'event' => ($event ?? $this->event) . ':response',
            'data' => $payload,
            'channel' => $channel ?? $this->channel->getName(),
        ];

        // if payload only contains key "data"
        if (
            count($p) === 1
            && isset($payload['data'])
        ) {
            $p['data'] = $payload['data'];
        }

        // Pre-encode once (avoid repeated encoding)
        $encoded = json_encode($p);

        $this->connection->send($encoded);

        return true;
    }

    final public function error(
        array|string|null $payload = null,
        ?string $event = null,
        ?string $channel = null
    ): bool {
        if (is_string($payload)) {
            $payload = [
                'message' => $payload,
            ];
        }

        $p = [
            'event' => ($event ?? $this->event) . ':error',
            'data' => $payload,
            'channel' => $channel ?? $this->channel->getName(),
        ];

        // if payload only contains key "data"
        if (
            count($p) === 1
            && isset($payload['data'])
        ) {
            $p['data'] = $payload['data'];
        }

        // get line from where this is called from
        $trace = debug_backtrace();
        $p['data']['trace'] = $trace
            ? $trace[0]['line']
            : null;

        Log::channel('websocket')->error('Send error: ' . @$p['data']['message'], $p);

        $this->connection->send(json_encode($p));

        return true;
    }

    final public function broadcast(
        array|string|null $payload = null,
        ?string $event = null,
        ?string $channel = null,
        bool $including_self = false
    ) {
        if (is_string($payload)) {
            $payload = [
                'message' => $payload,
            ];
        }

        $channel ??= ($this->channel ? $this->channel->getName() : null);

        $p = [
            'event' => ($event ?? $this->event),
            'data' => $payload,
            'channel' => $channel,
        ];

        if ($this->isMockConnection) {
            $this->connection->broadcast($p, $channel, $including_self);
            return;
        }

        // Direct broadcast (non-forked context, e.g. testing)
        if (! $channel) {
            $this->error('Channel not found');
            return;
        }

        $encoded = json_encode($p);

        foreach ($this->channel->getConnections() as $channel_conection) {
            if ($channel_conection !== $this->connection) {
                $channel_conection->send($encoded);
            }
        }

        if ($including_self) {
            $this->connection->send($encoded);
        }
    }

    final public function whisper(
        array|string|null $payload = null,
        ?string $event = null,
        array $socketIds,
        ?string $channel = null
    ) {
        if (is_string($payload)) {
            $payload = [
                'message' => $payload,
            ];
        }

        $channel ??= ($this->channel ? $this->channel->getName() : null);

        $p = [
            'event' => ($event ?? $this->event),
            'data' => $payload,
            'channel' => $channel,
        ];

        if ($this->isMockConnection) {
            $this->connection->whisper($p, $socketIds, $channel);
            return;
        }

        // Direct whisper (non-forked context, e.g. testing)
        $encoded = json_encode($p);
        $socketIdLookup = array_flip($socketIds);
        $sentTo = [];

        $this->channelManager->getLocalConnections()->then(function ($connections) use ($socketIdLookup, $encoded, &$sentTo) {
            foreach ($connections as $connection) {
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

    /**
     * Handle introspection requests (dev-only).
     *
     * - `websocket` → list all controllers and their methods
     * - `auth` → list all methods on AuthController
     */
    private static function handleIntrospection(
        ConnectionInterface $connection,
        array $message,
        string $prefix
    ) {
        // Only allow in local environment or when explicitly enabled
        $allowed = config('websockets.introspection', false)
            || app()->environment('local');

        if (! $allowed) {
            return self::send_error($connection, $message, 'Introspection disabled');
        }

        $prefix = static::without_uniquifyer($prefix);

        // Special case: "websocket" lists all controllers
        if ($prefix === 'websocket') {
            $controllers = self::introspectAllControllers();
            $connection->send(json_encode([
                'event' => ($message['event'] ?? 'websocket') . ':response',
                'data' => $controllers,
                'channel' => $message['channel'] ?? null,
            ]));
            return $controllers;
        }

        // Specific controller: "auth" → AuthController
        $controllerClass = ControllerResolver::resolve($prefix);
        if (! $controllerClass) {
            return self::send_error($connection, $message, "No controller found for '{$prefix}'");
        }

        $info = self::introspectController($controllerClass, $prefix);
        $connection->send(json_encode([
            'event' => ($message['event'] ?? $prefix) . ':response',
            'data' => $info,
            'channel' => $message['channel'] ?? null,
        ]));
        return $info;
    }

    /**
     * Introspect a single controller: list public methods, auth, lifecycle.
     */
    private static function introspectController(string $controllerClass, string $prefix): array
    {
        $reflection = new \ReflectionClass($controllerClass);
        $needAuth = true;

        // Check need_auth property
        if ($reflection->hasProperty('need_auth')) {
            $prop = $reflection->getProperty('need_auth');
            $needAuth = $prop->getDefaultValue() ?? true;
        }

        // Check lifecycle methods
        $hasBoot = $reflection->getMethod('boot')->getDeclaringClass()->getName() !== self::class;
        $hasBooted = $reflection->getMethod('booted')->getDeclaringClass()->getName() !== self::class;
        $hasUnboot = $reflection->getMethod('unboot')->getDeclaringClass()->getName() !== self::class;

        // Collect public non-inherited, non-magic methods
        $baseMethods = get_class_methods(self::class);
        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            // Skip inherited base methods, constructors, and magic methods
            if (in_array($name, $baseMethods, true)) continue;
            if (str_starts_with($name, '__')) continue;
            if ($method->isStatic()) continue;

            $info = ['name' => $name, 'event' => "{$prefix}.{$name}"];

            // Add parameter names (skip $connection, $data, $channel)
            $params = [];
            foreach ($method->getParameters() as $i => $param) {
                if ($i < 3) continue; // skip standard ($connection, $data, $channel)
                $params[] = '$' . $param->getName();
            }
            if ($params) $info['extra_params'] = $params;

            $methods[] = $info;
        }

        return [
            'controller' => $controllerClass,
            'prefix' => $prefix,
            'need_auth' => $needAuth,
            'lifecycle' => array_filter([
                'boot' => $hasBoot,
                'booted' => $hasBooted,
                'unboot' => $hasUnboot,
            ]),
            'methods' => $methods,
        ];
    }

    /**
     * Scan and introspect all available controllers.
     */
    private static function introspectAllControllers(): array
    {
        // Ensure controllers are scanned
        ControllerResolver::scanControllers();

        $result = [];
        $seen = [];

        // Scan app controllers directory
        $appPath = function_exists('app_path')
            ? app_path('Websocket/Controllers')
            : null;

        if ($appPath && is_dir($appPath)) {
            self::scanControllersInPath($appPath, '\\App\\Websocket\\Controllers\\', $result, $seen);
        }

        // Scan vendor controllers
        $vendorPath = __DIR__ . '/Controllers';
        if (is_dir($vendorPath)) {
            self::scanControllersInPath($vendorPath, '\\BlaxSoftware\\LaravelWebSockets\\Websocket\\Controllers\\', $result, $seen);
        }

        return [
            'controllers' => $result,
            'total' => count($result),
        ];
    }

    /**
     * Recursively scan a directory for controllers and introspect them.
     */
    private static function scanControllersInPath(
        string $path,
        string $namespace,
        array &$result,
        array &$seen,
        string $subNamespace = ''
    ): void {
        $iterator = new \DirectoryIterator($path);

        foreach ($iterator as $item) {
            if ($item->isDot()) continue;

            if ($item->isDir()) {
                self::scanControllersInPath(
                    $item->getPathname(),
                    $namespace,
                    $result,
                    $seen,
                    $subNamespace . $item->getFilename() . '\\'
                );
            } elseif ($item->isFile() && $item->getExtension() === 'php') {
                $className = $item->getBasename('.php');
                if (! str_ends_with($className, 'Controller')) continue;

                $fullClass = $namespace . $subNamespace . $className;
                if (isset($seen[$fullClass])) continue;
                $seen[$fullClass] = true;

                try {
                    if (! class_exists($fullClass, true)) continue;
                } catch (\Throwable $e) {
                    // Class redeclaration or autoload error (e.g. namespace mismatch) — skip
                    continue;
                }
                if (! is_subclass_of($fullClass, self::class)) continue;

                // Derive event prefix from class name
                $shortName = str_replace('Controller', '', $className);
                $prefix = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $shortName));

                $result[] = self::introspectController($fullClass, $prefix);
            }
        }
    }

    private static function send_error(
        ConnectionInterface $connection,
        array $message,
        string $reason,
        bool $reported = false
    ) {
        $connection->send(json_encode([
            'event' => ($message['event'] ?? 'unknown') . ':error',
            'data' => [
                'message' => $reason,
                'meta' => [
                    'reported' => $reported,
                ],
            ],
            'channel' => $message['channel'] ?? null,
        ]));

        return null;
    }

    protected static function get_uniquifyer($event)
    {
        preg_match('/[\[].*[\]]/', $event, $matches);
        if (count($matches) === 1) {
            $uniqiueifier = $matches[0];
        }

        return $uniqiueifier ?? null;
    }

    protected static function without_uniquifyer($event)
    {
        return preg_replace('/[\[].*[\]]/', '', $event);
    }

    private static function get_event($message)
    {
        // Split on '.' delimiter to get [controller, method, ...]
        // e.g. "admin.dashboard[abc]" → ["admin", "dashboard[abc]"]
        return explode('.', $message['event']);
    }
}
