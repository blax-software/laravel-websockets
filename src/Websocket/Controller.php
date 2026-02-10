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

    final public function __construct(
        protected ConnectionInterface $connection,
        protected PrivateChannel|Channel|PresenceChannel|null $channel,
        protected string $event,
        protected LocalChannelManager|RedisChannelManager $channelManager
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
        LocalChannelManager|RedisChannelManager $channelManager
    ) {
        $event = self::get_event($message);
        if (count($event) !== 2) {
            return self::send_error($connection, $message, 'Event unknown');
        }

        try {
            $eventPrefix = $event[0];
            $method = static::without_uniquifyer($event[1]);

            // Use cached controller resolver for fast lookup
            $controllerClass = ControllerResolver::resolve($eventPrefix);

            if (! $controllerClass) {
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
                $controller->error('Unauthorized');
                $controller->unboot();
                return;
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
