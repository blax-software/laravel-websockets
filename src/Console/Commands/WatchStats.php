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

        // Verbose: collect every authed userId whose per-socket cache blob is
        // missing, batch-load them from the auth provider's user model in one
        // query, and reuse the resolved subject when formatting each row. This
        // covers two real cases: connections that predate the cache writer
        // being added, and Redis evictions under memory pressure.
        $missingByUserId = [];
        foreach ($perChannel as $sockets) {
            foreach ($sockets as $socketId) {
                if (! isset($authedUsers[$socketId])) {
                    continue;
                }
                if (! WebsocketService::getAuth($socketId)) {
                    $missingByUserId[$authedUsers[$socketId]] = true;
                }
            }
        }
        $usersById = $this->loadAuthUsers(array_keys($missingByUserId));

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
                    $this->formatUser($socketId, $authedUsers, $usersById),
                    $this->formatDuration($socketId, $now),
                ];
            }
        }

        $this->table(['Channel', 'Connections', 'User', 'Duration'], $rows);
    }

    /**
     * Load users by id from the configured auth provider's model.
     *
     * Returns [id => model] for found ids; absent ids simply don't appear.
     * Returns [] silently on any failure (no auth provider, model missing,
     * DB unreachable) — this is admin tooling, not a critical path.
     */
    private function loadAuthUsers(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $model = config('auth.providers.users.model');
        if (! is_string($model) || ! class_exists($model)) {
            return [];
        }

        try {
            return $model::query()
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function formatUser(string $socketId, array $authedUsers, array $usersById): string
    {
        if (! isset($authedUsers[$socketId])) {
            return '<fg=gray>Guest</>';
        }

        $userId  = $authedUsers[$socketId];
        $subject = WebsocketService::getAuth($socketId) ?: ($usersById[$userId] ?? null);

        if (! $subject) {
            // Auth provider couldn't resolve the user either (deleted? auth
            // model not Eloquent?). Fall back to the bare id rather than
            // mislabeling as Guest, so debugging stays accurate.
            return '<fg=yellow>#' . $userId . '</>';
        }

        // Delegate to the bound IdentityFormatter so apps with non-User
        // subjects (Company, ApiClient, etc.) can render their own shape
        // without forking the package.
        return app(IdentityFormatter::class)->format($subject, $socketId);
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
