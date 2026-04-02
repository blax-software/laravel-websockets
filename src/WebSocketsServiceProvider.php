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

        $this->registerDefaultWebsocketChannels();
        $this->registerEventLoop();
        $this->registerWebSocketHandler();
        $this->registerRouter();
        $this->registerManagers();
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
            Console\Commands\SteerServer::class,
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

    protected function registerBroadcastAuthRoute()
    {
        if (! Route::has('broadcasting/auth')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }
    }

    protected function registerWebSocketHandler()
    {
        $this->app->singleton('websockets.handler', function () {
            return app(config('websockets.handlers.websocket'));
        });
    }
}
