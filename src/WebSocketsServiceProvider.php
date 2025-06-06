<?php

namespace BlaxSoftware\LaravelWebSockets;

use BlaxSoftware\LaravelWebSockets\Contracts\StatisticsCollector;
use BlaxSoftware\LaravelWebSockets\Contracts\StatisticsStore;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Controllers\AuthenticateDashboard;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Controllers\SendMessage;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Controllers\ShowApps;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Controllers\ShowDashboard;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Controllers\ShowStatistics;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Controllers\StoreApp;
use BlaxSoftware\LaravelWebSockets\Dashboard\Http\Middleware\Authorize as AuthorizeDashboard;
use BlaxSoftware\LaravelWebSockets\Queue\AsyncRedisConnector;
use BlaxSoftware\LaravelWebSockets\Server\Router;
use Clue\React\SQLite\DatabaseInterface;
use Clue\React\SQLite\Factory as SQLiteFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\MySQL\ConnectionInterface;
use React\MySQL\Factory as MySQLFactory;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class WebSocketsServiceProvider extends ServiceProvider
{
    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/websockets.php' => config_path('websockets.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__.'/../config/websockets.php', 'websockets'
        );

        $this->publishes([
            __DIR__.'/Websocket' => app_path('Websocket')
        ]);

        $this->registerDefaultWebsocketChannels();

        $this->registerEventLoop();

        $this->registerSQLiteDatabase();

        $this->registerMySqlDatabase();

        $this->registerAsyncRedisQueueDriver();

        $this->registerWebSocketHandler();

        $this->registerRouter();

        $this->registerManagers();

        $this->registerStatistics();

        $this->registerDashboard();

        $this->registerCommands();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        //
    }


    /**
     * Registers Broadcast::channel('websocket', fn () => true); in channels.php
     *
     * @return void
     */
    protected function registerDefaultWebsocketChannels()
    {
        \Illuminate\Support\Facades\Broadcast::channel('websocket', fn() => true);
    }

    protected function registerEventLoop()
    {
        $this->app->singleton(LoopInterface::class, function () {
            return Factory::create();
        });
    }

    /**
     * Register the async, non-blocking Redis queue driver.
     *
     * @return void
     */
    protected function registerAsyncRedisQueueDriver()
    {
        Queue::extend('async-redis', function () {
            return new AsyncRedisConnector($this->app['redis']);
        });
    }

    protected function registerSQLiteDatabase()
    {
        $this->app->singleton(DatabaseInterface::class, function () {
            $factory = new SQLiteFactory($this->app->make(LoopInterface::class));

            $database = $factory->openLazy(
                config('websockets.managers.sqlite.database', ':memory:')
            );

            $migrations = (new Finder())
                ->files()
                ->ignoreDotFiles(true)
                ->in(__DIR__.'/../database/migrations/sqlite')
                ->name('*.sql');

            /** @var SplFileInfo $migration */
            foreach ($migrations as $migration) {
                $database->exec($migration->getContents());
            }

            return $database;
        });
    }

    protected function registerMySqlDatabase()
    {
        $this->app->singleton(ConnectionInterface::class, function () {
            $factory = new MySQLFactory($this->app->make(LoopInterface::class));

            $connectionKey = 'database.connections.'.config('websockets.managers.mysql.connection');

            $auth = trim(config($connectionKey.'.username').':'.config($connectionKey.'.password'), ':');
            $connection = trim(config($connectionKey.'.host').':'.config($connectionKey.'.port'), ':');
            $database = config($connectionKey.'.database');

            $database = $factory->createLazyConnection(trim("{$auth}@{$connection}/{$database}", '@'));

            return $database;
        });
    }

    /**
     * Register the statistics-related contracts.
     *
     * @return void
     */
    protected function registerStatistics()
    {
        $this->app->singleton(StatisticsStore::class, function ($app) {
            $config = $app['config']['websockets'];
            $class = $config['statistics']['store'];

            return new $class;
        });

        $this->app->singleton(StatisticsCollector::class, function ($app) {
            $config = $app['config']['websockets'];
            $replicationMode = $config['replication']['mode'] ?? 'local';

            $class = $config['replication']['modes'][$replicationMode]['collector'];

            return new $class;
        });
    }

    /**
     * Register the dashboard components.
     *
     * @return void
     */
    protected function registerDashboard()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/', 'websockets');

        $this->registerDashboardRoutes();
        $this->registerDashboardGate();
    }

    /**
     * Register the package commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        $this->commands([
            Console\Commands\StartServer::class,
            Console\Commands\RestartServer::class,
            Console\Commands\CleanStatistics::class,
            Console\Commands\FlushCollectedStatistics::class,
        ]);
    }

    /**
     * Register the routing.
     *
     * @return void
     */
    protected function registerRouter()
    {
        $this->app->singleton('websockets.router', function () {
            return new Router;
        });
    }

    /**
     * Register the managers for the app.
     *
     * @return void
     */
    protected function registerManagers()
    {
        $this->app->singleton(Contracts\AppManager::class, function ($app) {
            $config = $app['config']['websockets'];

            return $this->app->make($config['managers']['app']);
        });
    }

    /**
     * Register the dashboard routes.
     *
     * @return void
     */
    protected function registerDashboardRoutes()
    {
        Route::group([
            'domain' => config('websockets.dashboard.domain'),
            'prefix' => config('websockets.dashboard.path'),
            'as' => 'laravel-websockets.',
            'middleware' => config('websockets.dashboard.middleware', [AuthorizeDashboard::class]),
        ], function () {
            Route::get('/', ShowDashboard::class)->name('dashboard');
            Route::get('/apps', ShowApps::class)->name('apps');
            Route::post('/apps', StoreApp::class)->name('apps.store');
            Route::get('/api/{appId}/statistics', ShowStatistics::class)->name('statistics');
            Route::post('/auth', AuthenticateDashboard::class)->name('auth');
            Route::post('/event', SendMessage::class)->name('event');
        });
    }

    /**
     * Register the dashboard gate.
     *
     * @return void
     */
    protected function registerDashboardGate()
    {
        Gate::define('viewWebSocketsDashboard', function ($user = null) {
            return $this->app->environment('local');
        });
    }

    protected function registerWebSocketHandler()
    {
        $this->app->singleton('websockets.handler', function () {
            return app(config('websockets.handlers.websocket'));
        });
    }
}
