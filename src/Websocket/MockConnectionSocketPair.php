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
