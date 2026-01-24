<?php

namespace BlaxSoftware\LaravelWebSockets;

use React\Promise\FulfilledPromise;
use React\Promise\PromiseInterface;

class Helpers
{
    /**
     * The loop used to create the Fulfilled Promise.
     *
     * @var null|\React\EventLoop\LoopInterface
     */
    public static $loop = null;

    /**
     * Cached promise resolver class to avoid repeated config() calls
     *
     * @var string|null
     */
    private static ?string $resolverClass = null;

    /**
     * Transform the Redis' list of key after value
     * to key-value pairs.
     * Optimized: Uses array_chunk instead of partition with modulo.
     *
     * @param  array  $list
     * @return array
     */
    public static function redisListToArray(array $list): array
    {
        if (empty($list)) {
            return [];
        }

        // Faster approach: chunk into pairs and combine
        $result = [];
        $count = count($list);
        for ($i = 0; $i < $count; $i += 2) {
            if (isset($list[$i + 1])) {
                $result[$list[$i]] = $list[$i + 1];
            }
        }

        return $result;
    }

    /**
     * Create a new fulfilled promise with a value.
     * Optimized: Caches the resolver class to avoid repeated config() lookups.
     *
     * @param  mixed  $value
     * @return \React\Promise\PromiseInterface
     */
    public static function createFulfilledPromise($value): PromiseInterface
    {
        // Cache the resolver class on first call
        if (self::$resolverClass === null) {
            self::$resolverClass = config(
                'websockets.promise_resolver',
                FulfilledPromise::class
            );
        }

        // PHP 8.0+ dynamic class instantiation
        $class = self::$resolverClass;
        return new $class($value, static::$loop);
    }

    /**
     * Reset the cached resolver class (useful for testing)
     */
    public static function resetResolverCache(): void
    {
        self::$resolverClass = null;
    }
}
