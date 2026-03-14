<?php

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\InteractsWithTime;

class SteerServer extends Command
{
    use InteractsWithTime;

    protected $signature = 'websocket:steer
        {action : The action to send (cache:clear, restart, restart:soft)}
        {--cache-driver=file : The cache driver to use for signaling.}';

    protected $description = 'Send a steering command to the running WebSocket server.';

    /** @var array<string, string> */
    protected array $actions = [
        'cache:clear'  => 'Clear OPcache and controller resolution cache (picks up new code without full restart)',
        'restart'      => 'Hard-restart the server (stops loop, supervisord restarts the process)',
        'restart:soft' => 'Soft-restart the server (gracefully close connections first)',
    ];

    public function handle(): int
    {
        $action = $this->argument('action');

        if (! array_key_exists($action, $this->actions)) {
            $this->error("Unknown action: {$action}");
            $this->line('');
            $this->info('Available actions:');
            foreach ($this->actions as $name => $desc) {
                $this->line("  <comment>{$name}</comment>  {$desc}");
            }
            return self::FAILURE;
        }

        $store = $this->option('cache-driver') ?: 'file';

        Cache::store($store)->forever('blax:websockets:steer', [
            'action' => $action,
            'time'   => $this->currentTime(),
        ]);

        \Log::channel('websocket')->info("WebSocket steer signal sent: {$action}");
        $this->info("Sent '{$action}' signal to the WebSocket server.");
        $this->line("<comment>{$this->actions[$action]}</comment>");

        return self::SUCCESS;
    }
}
