<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

/**
 * Per-connection session storage backed by Redis cache.
 *
 * Provides key-value storage scoped to a WebSocket connection.
 * Data persists across forked child processes (each child loads
 * from Redis, modifies, saves back — next child sees changes).
 *
 * Usage in controllers:
 *   wsSession()->put('key', $value);
 *   wsSession()->get('key', 'default');
 *   wsSession()->forget('key');
 *   wsSession()->all();
 *
 * Lifecycle:
 *   - Created on connection open (empty)
 *   - Loaded from Redis in each child process
 *   - Auto-saved before child exits (or explicitly via save())
 *   - Flushed on connection close
 */
class ConnectionSession
{
    private string $cacheKey;
    private array $data = [];
    private bool $loaded = false;
    private bool $dirty = false;

    /** @var int TTL in seconds (24 hours — safety net if onClose cleanup misses) */
    private const TTL = 86400;

    public function __construct(
        private readonly string $socketId
    ) {
        $this->cacheKey = 'ws_session_' . $socketId;
    }

    /**
     * Lazy-load session data from Redis on first access.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->data = cache()->get($this->cacheKey) ?? [];
        $this->loaded = true;
    }

    /**
     * Get a value from the session.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->data[$key] ?? $default;
    }

    /**
     * Store a value in the session.
     */
    public function put(string $key, mixed $value): static
    {
        $this->load();
        $this->data[$key] = $value;
        $this->dirty = true;
        return $this;
    }

    /**
     * Check if a key exists in the session.
     */
    public function has(string $key): bool
    {
        $this->load();
        return array_key_exists($key, $this->data);
    }

    /**
     * Remove a key from the session.
     */
    public function forget(string $key): static
    {
        $this->load();
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
        return $this;
    }

    /**
     * Get all session data.
     */
    public function all(): array
    {
        $this->load();
        return $this->data;
    }

    /**
     * Replace all session data.
     */
    public function replace(array $data): static
    {
        $this->load();
        $this->data = $data;
        $this->dirty = true;
        return $this;
    }

    /**
     * Increment a numeric value.
     */
    public function increment(string $key, int $amount = 1): int
    {
        $value = (int) $this->get($key, 0) + $amount;
        $this->put($key, $value);
        return $value;
    }

    /**
     * Save session data to Redis (only if modified).
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        if (empty($this->data)) {
            cache()->forget($this->cacheKey);
        } else {
            cache()->put($this->cacheKey, $this->data, self::TTL);
        }

        $this->dirty = false;
    }

    /**
     * Flush all session data and remove from Redis.
     */
    public function flush(): void
    {
        $this->data = [];
        $this->dirty = false;
        $this->loaded = true;
        cache()->forget($this->cacheKey);
    }

    /**
     * Check if session has unsaved changes.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Check if session is empty.
     */
    public function isEmpty(): bool
    {
        $this->load();
        return empty($this->data);
    }

    /**
     * Get the socket ID this session belongs to.
     */
    public function getSocketId(): string
    {
        return $this->socketId;
    }
}
