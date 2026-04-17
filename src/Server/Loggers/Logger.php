<?php

namespace BlaxSoftware\LaravelWebSockets\Server\Loggers;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;

class Logger
{
    /**
     * The console output interface.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $consoleOutput;

    /**
     * Whether the logger is enabled.
     *
     * @var bool
     */
    protected $enabled = false;

    /**
     * Whether the verbose mode is on.
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Check if the logger is active.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        $logger = app(WebSocketsLogger::class);

        return $logger->enabled;
    }

    /**
     * Create a new Logger instance.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $consoleOutput
     * @return void
     */
    public function __construct(OutputInterface $consoleOutput)
    {
        $this->consoleOutput = $consoleOutput;
    }

    /**
     * Enable the logger.
     *
     * @param  bool  $enabled
     * @return $this
     */
    public function enable($enabled = true)
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Enable the verbose mode.
     *
     * @param  bool  $verbose
     * @return $this
     */
    public function verbose($verbose = false)
    {
        $this->verbose = $verbose;

        return $this;
    }

    /**
     * Trigger an Info message.
     *
     * @param  string  $message
     * @return void
     */
    protected function info(string $message)
    {
        $this->line($message, 'info');
    }

    /**
     * Trigger a Warning message.
     *
     * @param  string  $message
     * @return void
     */
    protected function warn(string $message)
    {
        if (! $this->consoleOutput->getFormatter()->hasStyle('warning')) {
            $style = new OutputFormatterStyle('yellow');

            $this->consoleOutput->getFormatter()->setStyle('warning', $style);
        }

        $this->line($message, 'warning');
    }

    /**
     * Trigger an Error message.
     *
     * @param  string  $message
     * @return void
     */
    protected function error(string $message)
    {
        $this->line($message, 'error');
    }

    /**
     * Write a message to the console and persist it to the websocket log file.
     */
    protected function line(string $message, string $style)
    {
        // Console output (existing behavior)
        $this->consoleOutput->writeln(
            $style ? "<{$style}>{$message}</{$style}>" : $message
        );

        // Also persist to log file so errors are visible outside the console
        $this->fileLog($style, $message);
    }

    /**
     * Write a message to the websocket log channel.
     * Uses the 'websocket' channel if available, falls back to the default.
     */
    protected function fileLog(string $level, string $message): void
    {
        // Map console styles to log levels
        $logLevel = match ($level) {
            'error' => 'error',
            'warning' => 'warning',
            default => 'info',
        };

        try {
            $channel = config('logging.channels.websocket') ? 'websocket' : null;
            Log::channel($channel)->log($logLevel, '[WebSocket] ' . $message);
        } catch (\Throwable) {
            // Logging must never crash the WS server
        }
    }
}
