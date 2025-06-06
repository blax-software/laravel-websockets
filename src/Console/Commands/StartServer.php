<?php

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Facades\StatisticsCollector as StatisticsCollectorFacade;
use BlaxSoftware\LaravelWebSockets\Facades\WebSocketRouter;
use BlaxSoftware\LaravelWebSockets\Server\Loggers\ConnectionLogger;
use BlaxSoftware\LaravelWebSockets\Server\Loggers\HttpLogger;
use BlaxSoftware\LaravelWebSockets\Server\Loggers\WebSocketsLogger;
use BlaxSoftware\LaravelWebSockets\ServerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function React\Promise\all;

class StartServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'websockets:serve
        {--host=0.0.0.0}
        {--port=6001}
        {--cache-driver=file : The cache driver to use for the server. Redis will not work due to concurrency issues.}
        {--disable-statistics=true : Disable the statistics tracking.}
        {--statistics-interval= : The amount of seconds to tick between statistics saving.}
        {--debug : Forces the loggers to be enabled and thereby overriding the APP_DEBUG setting.}
        {--loop : Programatically inject the loop.}
    ';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Start the LaravelWebSockets server.';

    /**
     * Get the loop instance.
     *
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * The Pusher server instance.
     *
     * @var \Ratchet\Server\IoServer
     */
    public $server;

    /**
     * Initialize the command.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->loop = LoopFactory::create();
    }

    /**
     * Run the command.
     *
     * @return void
     */
    public function handle()
    {
        $this->components->info('Handling websocket server with pid ' . getmypid() . '...');

        // For is_fork() helper
        if (! defined('LARAVEL_PARENT_PID')) {
            define('LARAVEL_PARENT_PID', getmypid());
        }

        // For is_websocket() helper
        if (! defined('LARAVEL_IS_WEBSOCKET')) {
            define('LARAVEL_IS_WEBSOCKET', true);
        }

        // Fixes redis concurrency issues
        config(['cache.default' => $this->option('cache-driver', 'file')]);

        $this->laravel->singleton(LoopInterface::class, function () {
            return $this->loop;
        });

        $this->configureLoggers();

        $this->configureManagers();

        $this->configureStatistics();

        $this->configureRestartTimer();

        $this->configureRoutes();

        $this->configurePcntlSignal();

        $this->configurePongTracker();

        $this->startServer();
    }

    /**
     * Configure the loggers used for the console.
     *
     * @return void
     */
    protected function configureLoggers()
    {
        $this->configureHttpLogger();
        $this->configureMessageLogger();
        $this->configureConnectionLogger();
    }

    /**
     * Register the managers that are not resolved
     * in the package service provider.
     *
     * @return void
     */
    protected function configureManagers()
    {
        $this->laravel->singleton(ChannelManager::class, function ($app) {
            $config = $app['config']['websockets'];
            $mode = $config['replication']['mode'] ?? 'local';

            $class = $config['replication']['modes'][$mode]['channel_manager'];

            return new $class($this->loop);
        });
    }

    /**
     * Register the Statistics Collectors that
     * are not resolved in the package service provider.
     *
     * @return void
     */
    protected function configureStatistics()
    {
        if (! $this->option('disable-statistics')) {
            $intervalInSeconds = $this->option('statistics-interval') ?: config('websockets.statistics.interval_in_seconds', 3600);

            $this->loop->addPeriodicTimer($intervalInSeconds, function () {
                $this->line('Saving statistics...', null, OutputInterface::VERBOSITY_VERBOSE);

                StatisticsCollectorFacade::save();
            });
        }
    }

    /**
     * Configure the restart timer.
     *
     * @return void
     */
    public function configureRestartTimer()
    {
        $this->lastRestart = $this->getLastRestart();

        $this->loop->addPeriodicTimer(10, function () {
            if ($this->getLastRestart() !== $this->lastRestart) {
                $this->triggerSoftShutdown();
            }
        });
    }

    /**
     * Register the routes for the server.
     *
     * @return void
     */
    protected function configureRoutes()
    {
        WebSocketRouter::registerRoutes();
    }

    /**
     * Configure the PCNTL signals for soft shutdown.
     *
     * @return void
     */
    protected function configurePcntlSignal()
    {
        // When the process receives a SIGTERM or a SIGINT
        // signal, it should mark the server as unavailable
        // to receive new connections, close the current connections,
        // then stopping the loop.

        if (! extension_loaded('pcntl')) {
            throw new \RuntimeException('The pcntl extension is required to handle concurrency.');
        }

        $this->loop->addSignal(SIGTERM, function () {
            $this->line('Closing existing connections...');

            $this->triggerSoftShutdown();
        });

        $this->loop->addSignal(SIGINT, function () {
            $this->line('Closing existing connections...');

            $this->triggerSoftShutdown();
        });
    }

    /**
     * Configure the tracker that will delete
     * from the store the connections that.
     *
     * @return void
     */
    protected function configurePongTracker()
    {
        $this->loop->addPeriodicTimer(10, function () {
            $this->laravel
                ->make(ChannelManager::class)
                ->removeObsoleteConnections();
        });
    }

    /**
     * Configure the HTTP logger class.
     *
     * @return void
     */
    protected function configureHttpLogger()
    {
        $this->laravel->singleton(HttpLogger::class, function ($app) {
            return (new HttpLogger($this->output))
                ->enable($this->option('debug') ?: ($app['config']['app']['debug'] ?? false))
                ->verbose($this->output->isVerbose());
        });
    }

    /**
     * Configure the logger for messages.
     *
     * @return void
     */
    protected function configureMessageLogger()
    {
        $this->laravel->singleton(WebSocketsLogger::class, function ($app) {
            return (new WebSocketsLogger($this->output))
                ->enable($this->option('debug') ?: ($app['config']['app']['debug'] ?? false))
                ->verbose($this->output->isVerbose());
        });
    }

    /**
     * Configure the connection logger.
     *
     * @return void
     */
    protected function configureConnectionLogger()
    {
        $this->laravel->bind(ConnectionLogger::class, function ($app) {
            return (new ConnectionLogger($this->output))
                ->enable($app['config']['app']['debug'] ?? false)
                ->verbose($this->output->isVerbose());
        });
    }

    /**
     * Start the server.
     *
     * @return void
     */
    protected function startServer()
    {
        $this->components->info("Starting the WebSocket server on port {$this->option('port')}...");
        $this->comment('  <fg=yellow;options=bold>Press Ctrl+C to stop the server</>');
        $this->newLine();

        $this->buildServer();

        $this->server->run();
    }

    /**
     * Build the server instance.
     *
     * @return void
     */
    protected function buildServer()
    {
        $this->server = new ServerFactory(
            $this->option('host'),
            $this->option('port')
        );

        if ($loop = $this->option('loop')) {
            $this->loop = $loop;
        }

        $this->server = $this->server
            ->setLoop($this->loop)
            ->withRoutes(WebSocketRouter::getRoutes())
            ->setConsoleOutput($this->output)
            ->createServer();
    }

    /**
     * Get the last time the server restarted.
     *
     * @return int
     */
    protected function getLastRestart()
    {
        return Cache::get(
            'blax:websockets:restart',
            0
        );
    }

    /**
     * Trigger a soft shutdown for the process.
     *
     * @return void
     */
    protected function triggerSoftShutdown()
    {
        $channelManager = $this->laravel->make(ChannelManager::class);

        // Close the new connections allowance on this server.
        $channelManager->declineNewConnections();

        // Get all local connections and close them. They will
        // be automatically be unsubscribed from all channels.
        $channelManager->getLocalConnections()
            ->then(function ($connections) {
                return all(collect($connections)->map(function ($connection) {
                    return app('websockets.handler')
                        ->onClose($connection)
                        ->then(function () use ($connection) {
                            $connection->close();
                        });
                })->toArray());
            })
            ->then(function () {
                $this->loop->stop();
            });
    }
}
