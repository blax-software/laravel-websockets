<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;
use Illuminate\Console\Command;

/**
 * Live-updating display of WebSocket connection stats.
 *
 * Renders only the stats portion of `websockets:info` (total connections,
 * authenticated users, active channels, per-channel breakdown) and refreshes
 * every second by default.
 *
 * Polling vs. event-driven: stats live in the cache store (Redis in prod) and
 * are written by the running websocket process. There is no pub/sub channel
 * that emits a "stats changed" event, so a true event-driven approach would
 * require either tailing the cache writes or adding pub/sub plumbing into
 * WebsocketService. A 1-second poll is well under the granularity at which
 * connection counts are interesting and avoids that infrastructure cost.
 */
class WatchStats extends Command
{
    protected $signature = 'websockets:watch
        {--interval=1 : Refresh interval in seconds (minimum 1)}';

    protected $description = 'Live WebSocket stats display — refreshes every second until Ctrl+C.';

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));

        // Restore cursor visibility on Ctrl+C / kill so the terminal isn't
        // left in a broken state if the user interrupts mid-render.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $restore = function () {
                $this->output->write("\033[?25h");
                $this->newLine();
                exit(0);
            };
            pcntl_signal(SIGINT, $restore);
            pcntl_signal(SIGTERM, $restore);
        }

        $this->output->write("\033[?25l"); // hide cursor for cleaner refresh

        try {
            while (true) {
                $this->output->write("\033[2J\033[H"); // clear screen + home cursor

                $this->line('<fg=cyan;options=bold>WebSocket Server — Live Stats</>');
                $this->line('<fg=gray>' . now()->format('Y-m-d H:i:s') . ' — refreshing every ' . $interval . 's (Ctrl+C to exit)</>');
                $this->newLine();

                $this->renderStats();

                sleep($interval);
            }
        } finally {
            $this->output->write("\033[?25h"); // always restore cursor
        }

        return 0; // @phpstan-ignore-line
    }

    /**
     * Identical to ServerInfo::renderStats — duplicated rather than shared via
     * trait so that the two commands can evolve independently if needed (e.g.
     * the live view may eventually want sparkline-style deltas that the
     * one-shot view doesn't).
     */
    protected function renderStats(): void
    {
        $channels = WebsocketService::getActiveChannels();
        $authedUsers = WebsocketService::getAuthedUsers();

        $totalConnections = 0;
        $channelData = [];

        foreach ($channels as $channel) {
            $connections = WebsocketService::getChannelConnections($channel);
            $count = count($connections);
            $totalConnections += $count;
            $channelData[] = [$channel, $count];
        }

        $uniqueUsers = count(array_unique(array_values($authedUsers)));

        $this->components->twoColumnDetail('<fg=cyan;options=bold>Live Stats</>');
        $this->components->twoColumnDetail('Total connections', "<fg=white;options=bold>{$totalConnections}</>");
        $this->components->twoColumnDetail('Authenticated users', "<fg=white;options=bold>{$uniqueUsers}</>");
        $this->components->twoColumnDetail('Active channels', '<fg=white;options=bold>' . count($channels) . '</>');

        if (count($channelData) > 0) {
            $this->newLine();
            $this->table(['Channel', 'Connections'], $channelData);
        } else {
            $this->newLine();
            $this->line('  <fg=gray>No active channels.</>');
        }
    }
}
