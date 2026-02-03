<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Broadcast;

use Illuminate\Support\Facades\Log;

/**
 * Client for sending broadcasts to the WebSocket server.
 *
 * Uses a persistent Unix socket connection to efficiently send
 * multiple broadcast commands without connection overhead.
 *
 * This is a singleton - use BroadcastClient::instance() or the
 * global ws_broadcast() helper.
 */
class BroadcastClient
{
    /**
     * Singleton instance
     */
    protected static ?self $instance = null;

    /**
     * Socket connection to the broadcast server
     * @var resource|null
     */
    protected $socket = null;

    /**
     * Path to the Unix socket
     */
    protected string $socketPath;

    /**
     * Whether we're currently connected
     */
    protected bool $connected = false;

    /**
     * Maximum reconnection attempts
     */
    protected int $maxReconnectAttempts = 3;

    /**
     * Buffer for reading responses
     */
    protected string $readBuffer = '';

    protected function __construct()
    {
        $this->socketPath = config('websockets.broadcast_socket', '/tmp/laravel-websockets-broadcast.sock');
    }

    /**
     * Get the singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the singleton (useful for testing or when socket path changes)
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->disconnect();
            self::$instance = null;
        }
    }

    /**
     * Connect to the broadcast socket server
     */
    protected function connect(): bool
    {
        if ($this->connected && $this->socket !== null) {
            // Check if socket is still valid
            if ($this->isSocketValid()) {
                return true;
            }
            // Socket became invalid, disconnect and reconnect
            $this->disconnect();
        }

        if (!file_exists($this->socketPath)) {
            Log::debug('[BroadcastClient] Socket file does not exist: ' . $this->socketPath);
            return false;
        }

        $this->socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            1.0, // 1 second timeout for connection
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {
            Log::warning('[BroadcastClient] Failed to connect: ' . $errstr . ' (' . $errno . ')');
            $this->socket = null;
            return false;
        }

        // Set socket options for efficiency
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, 1); // 1 second read timeout

        $this->connected = true;
        $this->readBuffer = '';

        return true;
    }

    /**
     * Check if the socket is still valid
     */
    protected function isSocketValid(): bool
    {
        if ($this->socket === null) {
            return false;
        }

        // Check if socket is still open
        $meta = @stream_get_meta_data($this->socket);
        if ($meta === false || ($meta['eof'] ?? false)) {
            return false;
        }

        return true;
    }

    /**
     * Disconnect from the socket
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
        $this->readBuffer = '';
    }

    /**
     * Send a broadcast command to the WebSocket server
     *
     * @param string $event Event name
     * @param array $data Event data
     * @param string $channel Channel name (default: 'websocket')
     * @param array|null $sockets Target specific socket IDs (null = all)
     * @param array $excludeSockets Socket IDs to exclude
     * @return bool Success
     */
    public function send(
        string $event,
        array $data,
        string $channel = 'websocket',
        ?array $sockets = null,
        array $excludeSockets = []
    ): bool {
        $payload = [
            'event' => $event,
            'channel' => $channel,
            'data' => $data,
        ];

        if ($sockets !== null) {
            $payload['sockets'] = $sockets;
        }

        if (!empty($excludeSockets)) {
            $payload['exclude_sockets'] = $excludeSockets;
        }

        return $this->sendRaw($payload);
    }

    /**
     * Send raw payload to the broadcast server
     */
    protected function sendRaw(array $payload): bool
    {
        $message = json_encode($payload) . "\n";

        for ($attempt = 0; $attempt < $this->maxReconnectAttempts; $attempt++) {
            if (!$this->connect()) {
                // Socket not available, try after small delay
                if ($attempt < $this->maxReconnectAttempts - 1) {
                    usleep(10000); // 10ms
                }
                continue;
            }

            $written = @fwrite($this->socket, $message);

            if ($written === false || $written !== strlen($message)) {
                // Write failed, connection might be broken
                $this->disconnect();
                continue;
            }

            // Read response (optional, for confirmation)
            $response = $this->readResponse();

            if ($response !== null) {
                return $response['success'] ?? false;
            }

            // No response but write succeeded - assume success
            return true;
        }

        Log::warning('[BroadcastClient] Failed to send after ' . $this->maxReconnectAttempts . ' attempts');
        return false;
    }

    /**
     * Read a response from the socket
     */
    protected function readResponse(): ?array
    {
        if ($this->socket === null) {
            return null;
        }

        // Try to read with timeout
        $data = @fgets($this->socket, 8192);

        if ($data === false) {
            // Check if it's a timeout or error
            $meta = @stream_get_meta_data($this->socket);
            if (($meta['timed_out'] ?? false) || ($meta['eof'] ?? false)) {
                return null;
            }
            return null;
        }

        $data = trim($data);
        if ($data === '') {
            return null;
        }

        return json_decode($data, true);
    }

    /**
     * Whisper (send to specific sockets only)
     */
    public function whisper(
        string $event,
        array $data,
        array $sockets,
        string $channel = 'websocket'
    ): bool {
        return $this->send($event, $data, $channel, $sockets);
    }

    /**
     * Broadcast to all except specified sockets
     */
    public function broadcastExcept(
        string $event,
        array $data,
        array $excludeSockets,
        string $channel = 'websocket'
    ): bool {
        return $this->send($event, $data, $channel, null, $excludeSockets);
    }

    /**
     * Check if the broadcast socket is available
     */
    public function isAvailable(): bool
    {
        return file_exists($this->socketPath);
    }

    /**
     * Get the socket path
     */
    public function getSocketPath(): string
    {
        return $this->socketPath;
    }

    /**
     * Destructor - clean up socket
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
