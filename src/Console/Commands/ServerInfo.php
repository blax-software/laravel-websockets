<?php

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;
use Illuminate\Console\Command;

class ServerInfo extends Command
{
    protected $signature = 'websockets:info
        {--live : Live-updating display (like htop). Press Ctrl+C to exit.}
        {--interval=2 : Refresh interval in seconds for --live mode.}';

    protected $description = 'Show WebSocket server connection info, URL, and frontend secrets.';

    public function handle()
    {
        if ($this->option('live')) {
            return $this->liveLoop();
        }

        $this->renderInfo();

        return 0;
    }

    protected function renderInfo(): void
    {
        $this->renderConnectionDetails();
        $this->newLine();
        $this->renderFrontendSecrets();
        $this->newLine();
        $this->renderStats();
    }

    protected function renderConnectionDetails(): void
    {
        $app = config('websockets.apps.0', []);
        $port = config('websockets.port', config('websockets.dashboard.port', 6001));
        $host = $app['host'] ?? env('PUSHER_HOST', '127.0.0.1');
        $scheme = env('PUSHER_SCHEME', 'ws');

        $appId = $app['id'] ?? env('PUSHER_APP_ID', '—');
        $appPath = $app['path'] ?? env('PUSHER_APP_PATH', '');

        $internalUrl = "{$scheme}://0.0.0.0:{$port}/app/{$appId}";
        $externalUrl = "{$scheme}://{$host}:{$port}/app/{$appId}";

        if ($appPath) {
            $internalUrl .= "/{$appPath}";
            $externalUrl .= "/{$appPath}";
        }

        // Detect Traefik setup via APP_TRAEFIK env
        $traefikHost = env('APP_TRAEFIK');
        $traefikUrl = null;
        if ($traefikHost) {
            $traefikScheme = $scheme === 'ws' ? 'ws' : 'wss';
            $traefikWsHost = "ws-{$traefikHost}";
            $traefikUrl = "{$traefikScheme}://{$traefikWsHost}/app/{$appId}";
            if ($appPath) {
                $traefikUrl .= "/{$appPath}";
            }
        }

        $this->components->twoColumnDetail('<fg=cyan;options=bold>WebSocket Server</>');
        $this->components->twoColumnDetail('Internal URL', "<fg=green>{$internalUrl}</>");
        $this->components->twoColumnDetail('External URL', "<fg=green>{$externalUrl}</>");

        if ($traefikUrl) {
            $this->components->twoColumnDetail('Traefik URL', "<fg=yellow>{$traefikUrl}</>");
        }

        $this->components->twoColumnDetail('Port', (string) $port);
        $this->components->twoColumnDetail('Broadcast socket', config('websockets.broadcast_socket_enabled') ? '<fg=green>enabled</>' : '<fg=red>disabled</>');

        if (config('websockets.broadcast_socket_enabled')) {
            $socketPath = config('websockets.broadcast_socket', '/tmp/laravel-websockets-broadcast.sock');
            $socketAvailable = ws_available();
            $this->components->twoColumnDetail(
                'Socket path',
                $socketPath . ' ' . ($socketAvailable ? '<fg=green>(listening)</>' : '<fg=red>(not listening)</>')
            );
        }
    }

    protected function renderFrontendSecrets(): void
    {
        $app = config('websockets.apps.0', []);
        $port = config('websockets.port', config('websockets.dashboard.port', 6001));

        $key = $app['key'] ?? env('PUSHER_APP_KEY', '—');
        $host = $app['host'] ?? env('PUSHER_HOST', '127.0.0.1');
        $scheme = env('PUSHER_SCHEME', 'ws');
        $cluster = env('PUSHER_APP_CLUSTER', 'mt1');

        $this->components->twoColumnDetail('<fg=cyan;options=bold>Frontend Connection Secrets</>');
        $this->components->twoColumnDetail('PUSHER_APP_KEY', "<fg=yellow>{$key}</>");
        $this->components->twoColumnDetail('PUSHER_HOST', $host);
        $this->components->twoColumnDetail('PUSHER_PORT', (string) $port);
        $this->components->twoColumnDetail('PUSHER_SCHEME', $scheme);
        $this->components->twoColumnDetail('PUSHER_APP_CLUSTER', $cluster);

        $traefikHost = env('APP_TRAEFIK');
        if ($traefikHost) {
            $this->components->twoColumnDetail('Traefik WS host', "<fg=yellow>ws-{$traefikHost}</>");
        }
    }

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

    protected function liveLoop(): int
    {
        $interval = max(1, (int) $this->option('interval'));

        $this->info("Live mode — refreshing every {$interval}s. Press Ctrl+C to exit.");
        $this->newLine();

        while (true) {
            // Clear screen
            $this->output->write("\033[2J\033[H");

            $this->line('<fg=cyan;options=bold>  WebSocket Server — Live Stats</>');
            $this->line('  <fg=gray>' . now()->format('Y-m-d H:i:s') . ' — refreshing every ' . $interval . 's (Ctrl+C to exit)</>');
            $this->newLine();

            $this->renderStats();

            sleep($interval);
        }

        return 0; // @phpstan-ignore-line
    }
}
