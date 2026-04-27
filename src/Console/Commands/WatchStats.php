<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use BlaxSoftware\LaravelWebSockets\Contracts\IdentityFormatter;
use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;
use Illuminate\Console\Command;

/**
 * Live-updating display of WebSocket connection stats.
 *
 * Renders only the stats portion of `websockets:info` (total connections,
 * authenticated users, active channels, per-channel breakdown) and refreshes
 * every second by default.
 *
 * With -v / --verbose, the channels table expands to include one sub-row per
 * connection showing the socket id, the authenticated user (or "Guest"), and
 * how long the connection has been open. Connection start times are read from
 * the per-socket cache key written by Handler::establishConnection().
 *
 * Polling vs. event-driven: stats live in the cache store (Redis in prod) and
 * are written by the running websocket process. There is no pub/sub channel
 * that emits a "stats changed" event today, so a true event-driven approach
 * would require threading pub/sub publishes through every connection-mutation
 * call site. A 1-second poll is well under the granularity at which connection
 * counts are interesting and avoids that infrastructure cost.
 */
class WatchStats extends Command
{
    protected $signature = 'websockets:watch
        {--interval=1 : Refresh interval in seconds (minimum 1)}';

    protected $description = 'Live WebSocket stats display — refreshes every second until Ctrl+C. Pass -v to expand each channel into per-connection rows (socket id, user, duration).';

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));
        // Verbose mode piggybacks on Symfony's built-in -v / --verbose flag
        // because that short option is reserved and can't be redeclared.
        $verbose = $this->output->isVerbose();

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

                $modeLabel = $verbose ? ' [verbose]' : '';
                $this->line('<fg=cyan;options=bold>WebSocket Server — Live Stats' . $modeLabel . '</>');
                $this->line('<fg=gray>' . now()->format('Y-m-d H:i:s') . ' — refreshing every ' . $interval . 's (Ctrl+C to exit)</>');
                $this->newLine();

                $this->renderStats($verbose);

                sleep($interval);
            }
        } finally {
            $this->output->write("\033[?25h"); // always restore cursor
        }

        return 0; // @phpstan-ignore-line
    }

    protected function renderStats(bool $verbose): void
    {
        $channels    = WebsocketService::getActiveChannels();
        $authedUsers = WebsocketService::getAuthedUsers(); // [socketId => userId]

        $totalConnections = 0;
        $perChannel = []; // [channelName => [socketId, ...]]

        foreach ($channels as $channel) {
            $sockets = WebsocketService::getChannelConnections($channel);
            $perChannel[$channel] = $sockets;
            $totalConnections += count($sockets);
        }

        $uniqueUsers = count(array_unique(array_values($authedUsers)));

        $this->components->twoColumnDetail('<fg=cyan;options=bold>Live Stats</>');
        $this->components->twoColumnDetail('Total connections', "<fg=white;options=bold>{$totalConnections}</>");
        $this->components->twoColumnDetail('Authenticated users', "<fg=white;options=bold>{$uniqueUsers}</>");
        $this->components->twoColumnDetail('Active channels', '<fg=white;options=bold>' . count($channels) . '</>');

        if (empty($perChannel)) {
            $this->newLine();
            $this->line('  <fg=gray>No active channels.</>');
            return;
        }

        $this->newLine();

        if (! $verbose) {
            $rows = [];
            foreach ($perChannel as $channel => $sockets) {
                $rows[] = [$channel, count($sockets)];
            }
            $this->table(['Channel', 'Connections'], $rows);
            return;
        }

        // Verbose: one summary row per channel, then one sub-row per connection.
        // The sub-rows fill the User and Duration columns; the summary fills
        // Channel and Connections. Dashes mark "not applicable on this row".
        $now  = time();
        $rows = [];
        foreach ($perChannel as $channel => $sockets) {
            $rows[] = [
                "<fg=white;options=bold>{$channel}</>",
                "<fg=white;options=bold>" . count($sockets) . "</>",
                '-',
                '-',
            ];

            foreach ($sockets as $socketId) {
                $rows[] = [
                    '  <fg=gray>↳</>',
                    "<fg=gray>{$socketId}</>",
                    $this->formatUser($socketId, $authedUsers),
                    $this->formatDuration($socketId, $now),
                ];
            }
        }

        $this->table(['Channel', 'Connections', 'User', 'Duration'], $rows);
    }

    private function formatUser(string $socketId, array $authedUsers): string
    {
        if (! isset($authedUsers[$socketId])) {
            return '<fg=gray>Guest</>';
        }

        $subject = WebsocketService::getAuth($socketId);
        if (! $subject) {
            // Authed-but-expired: the socket is in ws_socket_authed_users
            // but the per-socket user blob has fallen out of cache. Show the
            // bare id from authedUsers so the connection isn't mislabeled
            // as a Guest.
            return '<fg=yellow>#' . $authedUsers[$socketId] . '</>';
        }

        // Delegate formatting to the bound IdentityFormatter so apps with
        // non-User subjects (Company, ApiClient, etc.) can render their own
        // shape without forking the package.
        $formatter = app(IdentityFormatter::class);
        return $formatter->format($subject, $socketId);
    }

    private function formatDuration(string $socketId, int $now): string
    {
        $info = WebsocketService::getConnection($socketId);
        $connectedAt = is_array($info) ? ($info['connected_at'] ?? null) : null;

        if (! is_int($connectedAt)) {
            // Connection predates the connected_at tracking, or the cache
            // entry was lost. Show a placeholder rather than 00:00:00 which
            // would falsely imply a brand-new connection.
            return '<fg=gray>—</>';
        }

        $secs = max(0, $now - $connectedAt);
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
