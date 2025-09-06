<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

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

    public function __construct($original_connection)
    {
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

        $this->socketId = optional($original_connection)->socketId;
        $this->user = optional($original_connection)->user;
        $this->remoteAddress = optional($original_connection)->remoteAddress;
        $this->ip ??= optional($original_connection)->ip;

        return $this;
    }

    public function send($data)
    {
        if(cache()->get('dedicated_data_'.getmypid().'_complete')){
            Log::error('[MockConnection] Send for pid: ' . getmypid() . ' which is already completed and does not check for new data', [
                'data' => $data,
            ]);
            return $this;
        }

        Log::channel('websocket')->info('[MockConnection] Send for pid: ' . getmypid(), [
            'data' => $data,
        ]);

        $key = static::getDataKey();

        cache()->put($key, $data, 60);
        cache()->put($key . '_done', true, 60);

        return $this;
    }

    private static function getDataKey()
    {
        $key = 'dedicated_data_' . getmypid();
        $i = '';

        while (cache()->has($key . ($i !== '' ? '_' . $i : ''))) {
            if ($i === '') {
                $i = 0;
            } else {
                $i = (int) $i;
                $i++;
            }
        }

        if ($i !== '') {
            $i = '_' . $i;
        }

        $key .= $i;

        return $key;
    }
}
