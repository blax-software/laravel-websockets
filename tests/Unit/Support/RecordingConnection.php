<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Test\Unit\Support;

use Ratchet\ConnectionInterface;

/**
 * Minimal Ratchet connection double for testing — captures every payload
 * pushed via `send()` so the test can assert on the dispatcher's output.
 *
 * Mirrors the public-property shape that {@see \BlaxSoftware\LaravelWebSockets\Websocket\Controller}
 * pokes at (notably `$user` for the auth-gating path).
 */
class RecordingConnection implements ConnectionInterface
{
    public ?object $user = null;

    /** @var array<int, string> */
    public array $sentRaw = [];

    /** @var array<int, mixed> */
    public array $sentDecoded = [];

    /**
     * @param  string  $data
     */
    public function send($data): self
    {
        $this->sentRaw[] = (string) $data;
        $decoded = json_decode((string) $data, true);
        $this->sentDecoded[] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : (string) $data;
        return $this;
    }

    public function close(): void
    {
        // no-op
    }

    /**
     * Return the most recent payload sent (decoded if it was JSON).
     */
    public function lastPayload(): mixed
    {
        return $this->sentDecoded[array_key_last($this->sentDecoded)] ?? null;
    }
}
