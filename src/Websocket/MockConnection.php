<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\Cache\IpcCache;
use React\Socket\Connection;
use Illuminate\Support\Facades\Log;

class MockConnection extends Connection implements \Ratchet\ConnectionInterface
{
    public $socketId;
    public $user;
    public $tenant;
    public $tenantable;
    public $remoteAddress;
    public $ip;
    public $app;

    /**
     * Unique request ID for cache-based communication
     * Used instead of PID to avoid race conditions from PID reuse
     */
    protected string $requestId;

    /**
     * Track current iteration for multi-response scenarios
     */
    protected int $currentIteration = 0;

    public function __construct($original_connection, ?string $requestId = null)
    {
        // Generate fallback requestId if not provided (for backward compatibility)
        $this->requestId = $requestId ?? uniqid('req_', true) . '_' . bin2hex(random_bytes(4));

        // create an indisdinctable copy of the original connection
        foreach (get_object_vars($original_connection) as $key => $value) {
            $this->{$key} = $value;
        }

        // get all defined properties (including private and protected)
        $reflection = new \ReflectionClass($original_connection);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE);

        foreach ($properties as $property) {
            // if name includes send
            if (strpos($property->getName(), 'send') !== false) {
                continue;
            }

            try {
                $property->setAccessible(true);
                $this->{$property->getName()} = $property->getValue($original_connection);
            } catch (\Throwable $e) {
            }
        }

        $this->app = optional($original_connection)->app;
        $this->socketId = optional($original_connection)->socketId;
        $this->user = optional($original_connection)->user;
        $this->remoteAddress = optional($original_connection)->remoteAddress;
        $this->ip ??= optional($original_connection)->ip;

        return $this;
    }

    public function send($data)
    {
        $key = $this->getDataKey();
        $completeKey = $key . '_complete';

        if (IpcCache::get($completeKey)) {
            Log::error('[MockConnection] Send for request: ' . $this->requestId . ' which is already completed and does not check for new data', [
                'data' => $data,
            ]);
            return $this;
        }

        // if data is boolean, throw
        if (is_bool($data)) {
            throw new \InvalidArgumentException('Data must be a string or an object that can be converted to a string.');
        }

        Log::channel('websocket')->info('[MockConnection] Send for request: ' . $this->requestId . ' iteration: ' . $this->currentIteration, [
            'data' => $data,
        ]);

        // Use atomic set to avoid race conditions - IpcCache uses tmpfs for speed
        IpcCache::put($key, $data, 60);
        IpcCache::put($key . '_done', true, 60);

        // Increment iteration for next send call
        $this->currentIteration++;

        return $this;
    }

    public function broadcast(
        $data,
        ?string $channel = null,
        bool $including_self = false,
    ) {
        $data ??= [];
        $data['broadcast'] = true;
        $data['channel'] ??= $channel;
        $data['including_self'] = $including_self;

        return $this->send(json_encode($data));
    }

    public function whisper(
        $data,
        array $socketIds,
        ?string $channel = null,
    ) {
        $data ??= [];
        $data['whisper'] = true;
        $data['channel'] ??= $channel;
        $data['socket_ids'] = $socketIds;

        return $this->send(json_encode($data));
    }

    /**
     * Get the data key for the current request and iteration
     * Now uses the unique requestId instead of PID to avoid race conditions
     */
    private function getDataKey(): string
    {
        $baseKey = 'dedicated_data_' . $this->requestId;

        if ($this->currentIteration > 0) {
            return $baseKey . '_' . $this->currentIteration;
        }

        return $baseKey;
    }
}
