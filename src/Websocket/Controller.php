<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\ChannelManagers\LocalChannelManager;
use BlaxSoftware\LaravelWebSockets\ChannelManagers\RedisChannelManager;
use BlaxSoftware\LaravelWebSockets\Channels\Channel;
use BlaxSoftware\LaravelWebSockets\Channels\PresenceChannel;
use BlaxSoftware\LaravelWebSockets\Channels\PrivateChannel;
use Ratchet\ConnectionInterface;
use Illuminate\Support\Facades\Log;

class Controller
{
    protected bool $isMockConnection;
    protected ?MockConnection $mockConnectionClone = null;

    final public function __construct(
        protected ConnectionInterface $connection,
        protected PrivateChannel|Channel|PresenceChannel|null $channel,
        protected string $event,
        protected LocalChannelManager|RedisChannelManager $channelManager
    ) {
        // Cache class check to avoid repeated get_class() calls (reflection is slow)
        $this->isMockConnection = get_class($connection) === MockConnection::class;

        // Pre-clone MockConnection once if needed (reuse across method calls)
        if ($this->isMockConnection) {
            $this->mockConnectionClone = clone $connection;
        }
    }

    public static function controll_message(
        ConnectionInterface $connection,
        PrivateChannel|Channel|PresenceChannel $channel,
        array $message,
        LocalChannelManager|RedisChannelManager $channelManager
    ) {
        $event = self::get_event($message);
        if (count($event) !== 2) {
            return self::send_error($connection, $message, 'Event unknown');
        }

        try {
            $contr = (strpos($event[0], '-') >= 0)
                ? implode('', array_map(fn($item) => ucfirst($item), explode('-', $event[0])))
                : ucfirst($event[0]);

            $vendorcontroller = '\\BlaxSoftware\\LaravelWebSockets\\Websocket\\Controllers\\' . $contr . 'Controller';
            $appcontroller = '\\App\\Websocket\\Controllers\\' . $contr . 'Controller';
            $method = static::without_uniquifyer($event[1]);

            $controllerClass = class_exists($appcontroller)
                ? $appcontroller
                : (class_exists($vendorcontroller) ? $vendorcontroller : null);

            if (! $controllerClass) {
                return self::send_error($connection, $message, 'Event could not be associated');
            }

            if (! method_exists($controllerClass, $method)) {
                return self::send_error($connection, $message, 'Event could not be handled');
            }

            $controller = new $controllerClass(
                $connection,
                $channel,
                $message['event'],
                $channelManager
            );

            if (($controller->need_auth ?? true) && ! $connection->user) {
                return $controller->error('Unauthorized');
            }

            $payload = $controller->$method(
                $connection,
                @$message['data'] ?? [],
                $message['channel']
            );

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

        if ($this->isMockConnection) {
            $this->mockConnectionClone->send($encoded);
        } else {
            $this->connection->send($encoded);
        }

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

        if ($this->isMockConnection) {
            $this->mockConnectionClone->send($encoded);
        } else {
            $this->connection->send($encoded);
        }

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

        // Pre-encode once (avoid repeated encoding)
        $encoded = json_encode($p);

        if ($this->isMockConnection) {
            $this->mockConnectionClone->send($encoded);
        } else {
            $this->connection->send($encoded);
        }

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

        if (!$this->isMockConnection) {
            if (! $channel) {
                $this->error('Channel not found');
                return;
            }

            // Pre-encode ONCE - massive improvement for 100+ connections
            $encoded = json_encode($p);

            foreach ($this->channel->getConnections() as $channel_conection) {
                if ($channel_conection !== $this->connection) {
                    $channel_conection->send($encoded);
                }

                if ($including_self) {
                    $this->connection->send($encoded);
                }
            }
        } else {
            $this->mockConnectionClone->broadcast(
                $p,
                $channel,
                $including_self
            );
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

        if (!$this->isMockConnection) {
            if (! $channel) {
                $this->error('Channel not found');
                return;
            }

            // Pre-encode ONCE for all matching sockets
            $encoded = json_encode($p);

            // Use array_flip for O(1) lookup instead of O(n) in_array
            $socketIdLookup = array_flip($socketIds);

            foreach ($this->channel->getConnections() as $channel_conection) {
                if (isset($socketIdLookup[$channel_conection->socketId])) {
                    $channel_conection->send($encoded);
                }
            }
        } else {
            $this->mockConnectionClone->whisper(
                $p,
                $socketIds,
                $channel
            );
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
        $event = explode('.', $message['event']);

        if (strpos($event[0], 'pusher.') > -1) {
            $event = explode('.', $event[0]);
        }

        if (strpos($event[0], 'pusher:') > -1) {
            $event = explode(':', $event[0]);
        }

        return $event;
    }
}
