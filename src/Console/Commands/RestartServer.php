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
    protected $signature = 'websockets:restart';

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
        \Log::channel('websocket')->info('WebSocket restart server command called ...');

        config(['cache.default' => $this->option('cache-driver', 'file')]);

        Cache::forever(
            'blax:websockets:restart',
            $this->currentTime()
        );

        $this->info(
            'Broadcasted the restart signal to the WebSocket server!'
        );
    }
}
