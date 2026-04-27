<?php

namespace BlaxSoftware\LaravelWebSockets;

use BlaxSoftware\LaravelWebSockets\Server\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

class WebSocketsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/websockets.php' => config_path('websockets.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__ . '/../config/websockets.php',
            'websockets'
        );

        $this->publishes([
            __DIR__ . '/Websocket' => app_path('Websocket')
        ]);

        $this->registerWebsocketLogChannel();
        $this->registerDefaultWebsocketChannels();
        $this->registerEventLoop();
        $this->registerWebSocketHandler();
        $this->registerRouter();
        $this->registerManagers();
        $this->registerIdentityFormatter();
        $this->registerBroadcastAuthRoute();
        $this->registerCommands();
    }

    public function register()
    {
        //
    }

    protected function registerDefaultWebsocketChannels()
    {
        \Illuminate\Support\Facades\Broadcast::channel('websocket', fn() => true);
        \Illuminate\Support\Facades\Broadcast::channel('websocket.{session}', fn() => true);
    }

    protected function registerEventLoop()
    {
        $this->app->singleton(LoopInterface::class, function () {
            return Factory::create();
        });
    }

    protected function registerCommands()
    {
        $this->commands([
            Console\Commands\StartServer::class,
            Console\Commands\RestartServer::class,
            Console\Commands\RestartHard::class,
            Console\Commands\SteerServer::class,
            Console\Commands\ServerInfo::class,
            Console\Commands\WatchStats::class,
        ]);
    }

    protected function registerRouter()
    {
        $this->app->singleton('websockets.router', function () {
            return new Router;
        });
    }

    protected function registerManagers()
    {
        $this->app->singleton(Contracts\AppManager::class, function ($app) {
            $config = $app['config']['websockets'];

            return $this->app->make($config['managers']['app']);
        });
    }

    /**
     * Bind the identity formatter used by admin tooling (`websockets:watch -v`).
     *
     * Resolution order:
     *   1. Whatever is already bound to `Contracts\IdentityFormatter` — apps
     *      that called `$this->app->bind()` in their own provider win, because
     *      this register runs in `boot()` after their `register()`.
     *   2. The class named in `config('websockets.identity_formatter')`.
     *   3. The package default (`DefaultIdentityFormatter`).
     */
    protected function registerIdentityFormatter()
    {
        if ($this->app->bound(Contracts\IdentityFormatter::class)) {
            return;
        }

        $configured = config('websockets.identity_formatter');
        $class = is_string($configured) && $configured !== ''
            ? $configured
            : Identity\DefaultIdentityFormatter::class;

        $this->app->singleton(Contracts\IdentityFormatter::class, $class);
    }

    protected function registerBroadcastAuthRoute()
    {
        if (! Route::has('broadcasting/auth')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }
    }

    protected function registerWebsocketLogChannel()
    {
        // Register a dedicated 'websocket' log channel if the app hasn't defined one
        if (! config('logging.channels.websocket')) {
            config(['logging.channels.websocket' => [
                'driver' => 'daily',
                'path' => storage_path('logs/websocket.log'),
                'level' => 'debug',
                'days' => 7,
            ]]);
        }
    }

    protected function registerWebSocketHandler()
    {
        $this->app->singleton('websockets.handler', function () {
            return app(config('websockets.handlers.websocket'));
        });
    }
}
