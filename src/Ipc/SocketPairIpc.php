<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Ipc;

use React\EventLoop\LoopInterface;

/**
 * Event-driven IPC using Unix socket pairs.
 *
 * This provides instant notification when child process sends data,
 * eliminating the need for polling entirely.
 *
 * Usage:
 * 1. Before fork: $ipc = SocketPairIpc::create($loop);
 * 2. After fork in parent: $ipc->setupParent($onDataCallback);
 * 3. After fork in child: $ipc->setupChild(); $ipc->sendToParent($data);
 */
class SocketPairIpc
{
    /**
     * Socket pair: [0] = parent side, [1] = child side
     * @var resource[]|null
     */
    private ?array $sockets = null;

    /**
     * Event loop for async reading
     */
    private ?LoopInterface $loop = null;

    /**
     * Whether this instance is configured for parent or child
     */
    private ?string $role = null;

    /**
     * Stream wrapper for ReactPHP
     */
    private $stream = null;

    /**
     * Create a new socket pair for IPC.
     * Must be called BEFORE fork().
     */
    public static function create(LoopInterface $loop): self
    {
        $instance = new self();
        $instance->loop = $loop;

        // Create Unix socket pair
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            throw new \RuntimeException('Failed to create socket pair: ' . socket_strerror(socket_last_error()));
        }

        $instance->sockets = $sockets;

        return $instance;
    }

    /**
     * Setup parent side after fork.
     * Closes child socket and sets up async reading.
     *
     * @param callable $onData Called with data when child sends: function(mixed $data)
     * @param callable|null $onClose Called when child closes connection
     */
    public function setupParent(callable $onData, ?callable $onClose = null): void
    {
        if ($this->role !== null) {
            throw new \LogicException('IPC already configured');
        }

        $this->role = 'parent';

        // Close child side in parent
        socket_close($this->sockets[1]);

        // Set non-blocking for async reading
        socket_set_nonblock($this->sockets[0]);

        // Convert socket to stream for ReactPHP
        $this->stream = socket_export_stream($this->sockets[0]);

        if ($this->stream === false) {
            throw new \RuntimeException('Failed to export socket as stream');
        }

        // Buffer for handling partial reads
        $buffer = '';

        // Add to event loop - this is the key: no polling needed!
        $this->loop->addReadStream($this->stream, function ($stream) use ($onData, $onClose, &$buffer) {
            $data = @fread($stream, 65536);

            if ($data === false || $data === '') {
                // Connection closed - process any remaining buffer
                if ($buffer !== '') {
                    $onData($buffer);
                }
                $this->loop->removeReadStream($stream);
                fclose($stream);
                if ($onClose) {
                    $onClose();
                }
                return;
            }

            // Simple framing: messages are newline-delimited
            $buffer .= $data;

            // Process complete messages (newline-delimited)
            while (($pos = strpos($buffer, "\n")) !== false) {
                $message = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if ($message !== '') {
                    $onData($message);
                }
            }
        });
    }

    /**
     * Setup child side after fork.
     * Closes parent socket.
     */
    public function setupChild(): void
    {
        if ($this->role !== null) {
            throw new \LogicException('IPC already configured');
        }

        $this->role = 'child';

        // Close parent side in child
        socket_close($this->sockets[0]);
    }

    /**
     * Send data from child to parent.
     * Call only from child process after setupChild().
     * Data is newline-delimited (do not include newlines in data).
     */
    public function sendToParent(string $data): bool
    {
        if ($this->role !== 'child') {
            throw new \LogicException('sendToParent can only be called from child');
        }

        // Newline-delimited framing
        $message = $data . "\n";
        $written = socket_write($this->sockets[1], $message, strlen($message));

        return $written === strlen($message);
    }

    /**
     * Close child socket (call at end of child process)
     */
    public function closeChild(): void
    {
        if ($this->role === 'child' && $this->sockets[1]) {
            socket_close($this->sockets[1]);
        }
    }

    /**
     * Get the child socket for the MockConnection
     * @return resource
     */
    public function getChildSocket()
    {
        return $this->sockets[1];
    }

    /**
     * Check if socket pairs are supported on this system
     */
    public static function isSupported(): bool
    {
        return function_exists('socket_create_pair')
            && function_exists('socket_export_stream');
    }
}
