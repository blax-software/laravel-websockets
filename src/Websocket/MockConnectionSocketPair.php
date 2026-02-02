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
        $data['broadcast'] = true;
        $data['channel'] ??= $channel;
        $data['including_self'] = $including_self;

        return $this->send(json_encode($data));
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
        $data['whisper'] = true;
        $data['channel'] ??= $channel;
        $data['socket_ids'] = $socketIds;

        return $this->send(json_encode($data));
    }

    public function close(): void
    {
        // No-op for mock
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
