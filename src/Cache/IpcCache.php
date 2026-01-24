<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Cache;

/**
 * High-performance IPC cache using tmpfs (RAM-backed filesystem).
 *
 * This provides near-memory speeds for inter-process communication
 * without the complexity of shared memory segments (shmop).
 *
 * On Linux, /dev/shm is mounted as tmpfs (RAM).
 * Falls back to /tmp if /dev/shm is not available.
 */
class IpcCache
{
    /**
     * Base directory for IPC files
     */
    private static ?string $baseDir = null;

    /**
     * Whether we're using tmpfs (RAM-backed)
     */
    private static ?bool $isTmpfs = null;

    /**
     * Initialize the base directory
     */
    private static function init(): void
    {
        if (self::$baseDir !== null) {
            return;
        }

        // Prefer /dev/shm (RAM-backed on Linux)
        if (is_dir('/dev/shm') && is_writable('/dev/shm')) {
            self::$baseDir = '/dev/shm/laravel-ws-ipc';
            self::$isTmpfs = true;
        } else {
            // Fall back to /tmp (may or may not be tmpfs)
            self::$baseDir = '/tmp/laravel-ws-ipc';
            self::$isTmpfs = false;
        }

        if (!is_dir(self::$baseDir)) {
            @mkdir(self::$baseDir, 0755, true);
        }
    }

    /**
     * Get the file path for a cache key
     */
    private static function getPath(string $key): string
    {
        self::init();
        // Use hash to avoid filesystem issues with special characters
        return self::$baseDir . '/' . md5($key);
    }

    /**
     * Check if a key exists (file stat only - very fast)
     */
    public static function has(string $key): bool
    {
        return file_exists(self::getPath($key));
    }

    /**
     * Get a value from cache
     *
     * @return mixed|null Returns null if not found
     */
    public static function get(string $key): mixed
    {
        $path = self::getPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        // Check expiration (stored as first 10 bytes)
        $expireAt = (int) substr($content, 0, 10);
        if ($expireAt > 0 && $expireAt < time()) {
            @unlink($path);
            return null;
        }

        $data = substr($content, 10);
        return $data === '' ? null : unserialize($data);
    }

    /**
     * Set a value in cache
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds (0 = no expiry)
     */
    public static function put(string $key, mixed $value, int $ttl = 60): bool
    {
        $path = self::getPath($key);
        $expireAt = $ttl > 0 ? time() + $ttl : 0;

        // Format: 10 bytes for expiry timestamp + serialized data
        $content = sprintf('%010d', $expireAt) . serialize($value);

        // Atomic write: write to temp file then rename
        $tempPath = $path . '.' . getmypid();
        if (@file_put_contents($tempPath, $content) === false) {
            return false;
        }

        return @rename($tempPath, $path);
    }

    /**
     * Delete a key from cache
     */
    public static function forget(string $key): bool
    {
        $path = self::getPath($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return true;
    }

    /**
     * Delete multiple keys from cache
     */
    public static function forgetMultiple(array $keys): void
    {
        foreach ($keys as $key) {
            self::forget($key);
        }
    }

    /**
     * Set multiple values atomically
     *
     * @param array<string, mixed> $values Key => Value pairs
     * @param int $ttl Time to live in seconds
     */
    public static function putMultiple(array $values, int $ttl = 60): void
    {
        foreach ($values as $key => $value) {
            self::put($key, $value, $ttl);
        }
    }

    /**
     * Clean up expired cache files (call periodically)
     */
    public static function cleanup(): int
    {
        self::init();

        $cleaned = 0;
        $now = time();

        $files = @scandir(self::$baseDir);
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = self::$baseDir . '/' . $file;
            $content = @file_get_contents($path);

            if ($content === false) {
                continue;
            }

            $expireAt = (int) substr($content, 0, 10);
            if ($expireAt > 0 && $expireAt < $now) {
                @unlink($path);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Check if we're using RAM-backed storage
     */
    public static function isTmpfs(): bool
    {
        self::init();
        return self::$isTmpfs ?? false;
    }

    /**
     * Reset (for testing)
     */
    public static function reset(): void
    {
        self::$baseDir = null;
        self::$isTmpfs = null;
    }
}
