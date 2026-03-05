<?php

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use Ratchet\ConnectionInterface;

/**
 * Mock connection for child processes using socket pair IPC.
 * Sends data directly via Unix socket instead of file-based cache.
 */
class MockConnectionSocketPair implements ConnectionInterface
{
    private ConnectionInterface $realConnection;
    private SocketPairIpc $ipc;

    public function __construct(ConnectionInterface $connection, SocketPairIpc $ipc)
    {
        $this->realConnection = $connection;
        $this->ipc = $ipc;
    }

    /**
     * Send data to parent via socket pair.
     * Parent receives INSTANTLY (event-driven, no polling!)
     */
    public function send($data): self
    {
        // Ensure data is a string (remove any embedded newlines for framing)
        $dataStr = is_string($data) ? $data : json_encode($data);
        $dataStr = str_replace(["\r\n", "\r", "\n"], ' ', $dataStr);

        $this->ipc->sendToParent($dataStr);
        return $this;
    }

    /**
     * Broadcast a message to all connections in a channel.
     * Serializes the data for the parent process to handle.
     */
    public function broadcast(
        $data,
        ?string $channel = null,
        bool $including_self = false,
    ): self {
        $data ??= [];
        $data['channel'] ??= $channel;
        $data['including_self'] = $including_self;

        // B: prefix for instant routing in parent (avoids JSON decode for regular responses)
        $this->ipc->sendToParent('B:' . json_encode($data));
        return $this;
    }

    /**
     * Whisper a message to specific socket IDs.
     * Serializes the data for the parent process to handle.
     */
    public function whisper(
        $data,
        array $socketIds,
        ?string $channel = null,
    ): self {
        $data ??= [];
        $data['channel'] ??= $channel;
        $data['socket_ids'] = $socketIds;

        // W: prefix for instant routing in parent (avoids JSON decode for regular responses)
        $this->ipc->sendToParent('W:' . json_encode($data));
        return $this;
    }

    public function close(): void
    {
        // No-op for mock
    }

    /**
     * Reset all user-set connection state on the parent process.
     *
     * Clears auth state (user, authLoaded) and any custom connection data
     * that was stored via setConnectionData(). Channels remain subscribed.
     *
     * Used after logout so the next WS message re-authenticates from Redis.
     */
    public function resetConnection(): void
    {
        $this->ipc->sendToParent('C:RESET');
    }

    /**
     * Store a custom key-value pair on the parent's connection object.
     *
     * The value is JSON-serialized over IPC and set as $connection->$key
     * on the parent process. Readable by any subsequent controller via
     * $this->connection->$key (proxied through __get).
     */
    public function setConnectionData(string $key, mixed $value): void
    {
        // Update local child copy for immediate reads within this request
        $this->realConnection->$key = $value;

        // Signal parent to persist the change
        $this->ipc->sendToParent('C:SET:' . $key . ':' . json_encode($value));
    }

    /**
     * Remove a custom key from the parent's connection object.
     */
    public function clearConnectionData(string $key): void
    {
        unset($this->realConnection->$key);
        $this->ipc->sendToParent('C:DEL:' . $key);
    }

    /**
     * Magic getter to proxy properties from real connection.
     */
    public function __get(string $name): mixed
    {
        return $this->realConnection->$name;
    }

    /**
     * Magic setter to proxy properties.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->realConnection->$name = $value;
    }

    /**
     * Magic isset to proxy property checks.
     */
    public function __isset(string $name): bool
    {
        return isset($this->realConnection->$name);
    }
}
