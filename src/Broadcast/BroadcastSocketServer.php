<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Broadcast;

use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use Illuminate\Support\Facades\Log;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixServer;

/**
 * Unix domain socket server for receiving broadcast commands.
 *
 * Runs inside the WebSocket server process and listens for broadcast
 * commands from other PHP processes (queue workers, HTTP requests, etc.)
 *
 * Protocol:
 * - Each message is newline-delimited JSON
 * - Format: {"channel": "...", "event": "...", "data": {...}, "sockets": [...]}
 */
class BroadcastSocketServer
{
    protected LoopInterface $loop;

    protected ?UnixServer $server = null;

    protected ChannelManager $channelManager;

    protected string $socketPath;

    /**
     * Active client connections
     * @var ConnectionInterface[]
     */
    protected array $clients = [];

    public function __construct(LoopInterface $loop, ChannelManager $channelManager)
    {
        $this->loop = $loop;
        $this->channelManager = $channelManager;
        $this->socketPath = config('websockets.broadcast_socket', '/tmp/laravel-websockets-broadcast.sock');
    }

    /**
     * Start the broadcast socket server
     */
    public function start(): void
    {
        // Remove stale socket file if exists
        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }

        try {
            $this->server = new UnixServer($this->socketPath, $this->loop);

            // Set permissions so other processes can connect
            chmod($this->socketPath, 0666);

            $this->server->on('connection', [$this, 'handleConnection']);
            $this->server->on('error', function (\Exception $e) {
                Log::error('[BroadcastSocket] Server error: ' . $e->getMessage());
            });

            Log::info('[BroadcastSocket] Listening on ' . $this->socketPath);
        } catch (\Exception $e) {
            Log::error('[BroadcastSocket] Failed to start: ' . $e->getMessage());
        }
    }

    /**
     * Handle a new client connection
     */
    public function handleConnection(ConnectionInterface $connection): void
    {
        $clientId = spl_object_hash($connection);
        $this->clients[$clientId] = $connection;
        $buffer = '';

        $connection->on('data', function ($data) use ($connection, &$buffer) {
            $buffer .= $data;

            // Process complete messages (newline-delimited)
            while (($pos = strpos($buffer, "\n")) !== false) {
                $message = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if ($message !== '') {
                    $this->handleMessage($connection, $message);
                }
            }
        });

        $connection->on('close', function () use ($clientId) {
            unset($this->clients[$clientId]);
        });

        $connection->on('error', function (\Exception $e) use ($clientId) {
            Log::warning('[BroadcastSocket] Client error: ' . $e->getMessage());
            unset($this->clients[$clientId]);
        });
    }

    /**
     * Handle a broadcast message from a client
     */
    protected function handleMessage(ConnectionInterface $connection, string $message): void
    {
        try {
            $payload = json_decode($message, true);

            if (!$payload || !isset($payload['event'])) {
                $connection->write(json_encode(['success' => false, 'error' => 'Invalid payload']) . "\n");
                return;
            }

            $channel = $payload['channel'] ?? 'websocket';
            $event = $payload['event'];
            $data = $payload['data'] ?? [];
            $sockets = $payload['sockets'] ?? null; // Target specific sockets
            $excludeSockets = $payload['exclude_sockets'] ?? []; // Exclude specific sockets

            // Get channel instance and broadcast
            $channelInstance = $this->channelManager->find('websockets', $channel);

            if ($channelInstance) {
                $this->broadcastToChannel($channelInstance, $event, $data, $sockets, $excludeSockets);
                $connection->write(json_encode(['success' => true]) . "\n");
            } else {
                // Channel doesn't exist or no subscribers - still success
                $connection->write(json_encode(['success' => true, 'warning' => 'No channel subscribers']) . "\n");
            }
        } catch (\Exception $e) {
            Log::error('[BroadcastSocket] Error handling message: ' . $e->getMessage());
            $connection->write(json_encode(['success' => false, 'error' => $e->getMessage()]) . "\n");
        }
    }

    /**
     * Broadcast to a channel
     */
    protected function broadcastToChannel($channel, string $event, array $data, ?array $sockets, array $excludeSockets): void
    {
        $payload = json_encode([
            'event' => $event,
            'channel' => $channel->getName(),
            'data' => $data,
        ]);

        // Get subscribers
        $subscribers = $channel->getSubscribedConnections();

        foreach ($subscribers as $connection) {
            $socketId = $connection->socketId ?? null;

            // Filter by specific sockets if provided
            if ($sockets !== null && !in_array($socketId, $sockets)) {
                continue;
            }

            // Exclude specific sockets
            if (in_array($socketId, $excludeSockets)) {
                continue;
            }

            $connection->send($payload);
        }
    }

    /**
     * Stop the server
     */
    public function stop(): void
    {
        if ($this->server) {
            $this->server->close();
            $this->server = null;
        }

        // Clean up socket file
        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }

        // Close all client connections
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }

    /**
     * Get the socket path
     */
    public function getSocketPath(): string
    {
        return $this->socketPath;
    }
}
