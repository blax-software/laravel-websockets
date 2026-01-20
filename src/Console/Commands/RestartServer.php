<?php

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\InteractsWithTime;

class RestartServer extends Command
{
    use InteractsWithTime;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websockets:restart
        {--cache-driver=file : The cache driver to use for the server. Redis will not work due to concurrency issues.}
        {--soft : Use soft shutdown (gracefully close connections) instead of hard shutdown.}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Signal the WebSockets server to restart.';

    /**
     * Run the command.
     *
     * @return void
     */
    public function handle()
    {
        \Log::channel('websocket')->debug('websockets:restart command started', [
            'cache_driver' => $this->option('cache-driver'),
            'soft' => $this->option('soft'),
        ]);

        \Log::channel('websocket')->info('WebSocket restart server command called ...');

        config(['cache.default' => $this->option('cache-driver', 'file')]);
        \Log::channel('websocket')->debug('Cache driver configured', ['driver' => $this->option('cache-driver', 'file')]);

        $restartData = [
            'time' => $this->currentTime(),
            'soft' => $this->option('soft'),
        ];

        \Log::channel('websocket')->debug('Storing restart signal in cache', $restartData);

        Cache::forever(
            'blax:websockets:restart',
            $restartData
        );

        \Log::channel('websocket')->debug('Restart signal stored successfully');

        $shutdownType = $this->option('soft') ? 'soft' : 'hard';
        $this->info(
            "Broadcasted the {$shutdownType} restart signal to the WebSocket server!"
        );

        \Log::channel('websocket')->info('Restart signal broadcasted', ['shutdown_type' => $shutdownType]);
    }
}
