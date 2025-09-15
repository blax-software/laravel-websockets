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
    final public function __construct(
        protected ConnectionInterface $connection,
        protected PrivateChannel|Channel|PresenceChannel|null $channel,
        protected string $event,
        protected LocalChannelManager|RedisChannelManager $channelManager
    ) {}

    public static function controll_message(
        ConnectionInterface $connection,
        PrivateChannel|Channel|PresenceChannel $channel,
        array $message,
        LocalChannelManager|RedisChannelManager $channelManager
    ) {
        $event = self::get_event($message);

        if (count($event) != 2) {
            return $connection->send(json_encode([
                'event' => $message['event'] . ':error',
                'data' => [
                    'message' => 'Event unknown',
                ],
                'channel' => $message['channel'],
            ]));
        }

        try {
            $contr = (strpos($event[0], '-') >= 0)
                ? implode('', array_map(fn ($item) => ucfirst($item), explode('-', $event[0])))
                : ucfirst($event[0]);

            $vendorcontroller = '\BlaxSoftware\LaravelWebSockets\Websocket\Controllers\\' . $contr . 'Controller';
            $appcontroller = '\App\Websocket\Controllers\\' . $contr . 'Controller';
            $method = static::without_uniquifyer($event[1]);

            $controller = class_exists($appcontroller)
                ? $appcontroller
                : $vendorcontroller;

            if (! $controller) {
                return $connection->send(json_encode([
                    'event' => $message['event'] . ':error',
                    'data' => [
                        'message' => 'Event could not be associated',
                    ],
                    'channel' => $message['channel'],
                ]));
            }

            if (! method_exists($controller, $method)) {
                return $connection->send(json_encode([
                    'event' => $message['event'] . ':error',
                    'data' => [
                        'message' => 'Event could not be handled',
                    ],
                    'channel' => $message['channel'],
                ]));
            }

            // Instantiate the controller
            $controller = (new $controller(
                $connection,
                $channel,
                $message['event'],
                $channelManager
            ));

            // Return unauthorized if auth is required
            if (($controller->need_auth ?? true) && ! $connection->user) {
                return $controller->error('Unauthorized');
            }

            $payload = $controller->$method(
                $connection,
                @$message['data'] ?? [],
                $message['channel']
            );

            if (
                $payload === false
                || $payload === true
            ) {
                return;
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

            return $connection->send(json_encode([
                'event' => $message['event'] . ':error',
                'data' => [
                    'message' => $e->getMessage(),
                    'meta' => [
                        'reported' => true,
                    ],
                ],
                'channel' => $message['channel'],
            ]));
        }

        return $connection->send(json_encode([
            'event' => $message['event'] . ':error',
            'data' => [
                'message' => 'An unknown error occured',
            ],
            'channel' => $message['channel'],
        ]));
    }

    final public function progress(
        mixed $payload = null,
        ?string $event = null,
        ?string $channel = null
    ) : bool {
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

        if (get_class($this->connection) === MockConnection::class) {
            $connection = clone $this->connection;
            $connection->send(json_encode($p));
        } else {
            $this->connection->send(json_encode($p));
        }

        return true;
    }

    final public function success(
        mixed $payload = null,
        ?string $event = null,
        ?string $channel = null
    ) : bool {
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

        if (get_class($this->connection) === MockConnection::class) {
            $connection = clone $this->connection;
            $connection->send(json_encode($p));
        } else {
            $this->connection->send(json_encode($p));
        }

        return true;
    }

    final public function error(
        array|string|null $payload = null,
        ?string $event = null,
        ?string $channel = null
    ) : bool {
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

        if (get_class($this->connection) === MockConnection::class) {
            $connection = clone $this->connection;
            $connection->send(json_encode($p));
        } else {
            $this->connection->send(json_encode($p));
        }

        return true;
    }

    final public function broadcast(
        array|string|null $payload = null,
        ?string $event = null,
        ?string $channel = null,
        bool $including_self = false
    ){
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

        if (get_class($this->connection) !== MockConnection::class) {
            if (! $channel) {
                $this->error('Channel not found');
                return;
            }

            foreach ($this->channel->getConnections() as $channel_conection) {
                if ($channel_conection !== $this->connection) {
                    $channel_conection->send(json_encode($p));
                }

                if ($including_self) {
                    $this->connection->send(json_encode($p));
                }
            }
        }else{
            $connection = clone $this->connection;
            $connection->broadcast(
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
    ){
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

        if (get_class($this->connection) !== MockConnection::class) {
            if (! $channel) {
                $this->error('Channel not found');
                return;
            }

            foreach ($this->channel->getConnections() as $channel_conection) {
                if (in_array($channel_conection->socketId, $socketIds)) {
                    $channel_conection->send(json_encode($p));
                }
            }
        }else{
            $connection = clone $this->connection;
            $connection->whisper(
                $p,
                $socketIds,
                $channel
            );
        }
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
