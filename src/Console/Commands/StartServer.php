<?php

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use BlaxSoftware\LaravelWebSockets\Cache\IpcCache;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Facades\StatisticsCollector as StatisticsCollectorFacade;
use BlaxSoftware\LaravelWebSockets\Facades\WebSocketRouter;
use BlaxSoftware\LaravelWebSockets\Ipc\SocketPairIpc;
use BlaxSoftware\LaravelWebSockets\Server\Loggers\ConnectionLogger;
use BlaxSoftware\LaravelWebSockets\Server\Loggers\HttpLogger;
use BlaxSoftware\LaravelWebSockets\Server\Loggers\WebSocketsLogger;
use BlaxSoftware\LaravelWebSockets\ServerFactory;
use BlaxSoftware\LaravelWebSockets\Services\WebsocketService;
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
        {--soft : Use soft shutdown (gracefully close connections) instead of hard shutdown.}
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
     * The last restart timestamp.
     *
     * @var int|null
     */
    protected $lastRestart = null;

    /**
     * Whether the last restart signal requested soft shutdown.
     *
     * @var bool
     */
    protected $restartSoftShutdown = false;

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
        try {
            \Log::channel('websocket')->debug('websockets:serve command started', [
                'pid' => getmypid(),
                'host' => $this->option('host'),
                'port' => $this->option('port'),
                'cache_driver' => $this->option('cache-driver'),
                'disable_statistics' => $this->option('disable-statistics'),
                'debug' => $this->option('debug'),
                'soft' => $this->option('soft'),
            ]);

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
            \Log::channel('websocket')->debug('Cache driver configured', ['driver' => $this->option('cache-driver', 'file')]);

            WebsocketService::resetAllTracking();
            \Log::channel('websocket')->debug('WebsocketService tracking reset');

            $this->laravel->singleton(LoopInterface::class, function () {
                return $this->loop;
            });
            \Log::channel('websocket')->debug('LoopInterface singleton registered');

            \Log::channel('websocket')->debug('Configuring loggers...');
            $this->configureLoggers();
            \Log::channel('websocket')->debug('Loggers configured');

            \Log::channel('websocket')->debug('Configuring managers...');
            $this->configureManagers();
            \Log::channel('websocket')->debug('Managers configured');

            \Log::channel('websocket')->debug('Configuring statistics...');
            $this->configureStatistics();
            \Log::channel('websocket')->debug('Statistics configured');

            \Log::channel('websocket')->debug('Configuring restart timer...');
            $this->configureRestartTimer();
            \Log::channel('websocket')->debug('Restart timer configured');

            \Log::channel('websocket')->debug('Configuring routes...');
            $this->configureRoutes();
            \Log::channel('websocket')->debug('Routes configured');

            \Log::channel('websocket')->debug('Configuring PCNTL signals...');
            $this->configurePcntlSignal();
            \Log::channel('websocket')->debug('PCNTL signals configured');

            // $this->configurePongTracker();

            \Log::channel('websocket')->debug('Starting server...');
            $this->startServer();
        } catch (\Throwable $e) {
            $this->error('Error starting the WebSocket server: ' . $e->getMessage());

            \Log::error('Error starting the WebSocket server: ', [
                'exception' => $e,
            ]);

            // if sentry is defined capture exception
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        }
    }

    /**
     * Configure the loggers used for the console.
     *
     * @return void
     */
    protected function configureLoggers()
    {
        \Log::channel('websocket')->debug('Configuring HTTP logger...');
        $this->configureHttpLogger();
        \Log::channel('websocket')->debug('Configuring message logger...');
        $this->configureMessageLogger();
        \Log::channel('websocket')->debug('Configuring connection logger...');
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

            \Log::channel('websocket')->debug('Creating ChannelManager', [
                'mode' => $mode,
                'class' => $class,
            ]);

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

            \Log::channel('websocket')->debug('Statistics enabled', [
                'interval_seconds' => $intervalInSeconds,
            ]);

            $this->loop->addPeriodicTimer($intervalInSeconds, function () {
                \Log::channel('websocket')->debug('Statistics timer tick, saving...');
                $this->line('Saving statistics...', null, OutputInterface::VERBOSITY_VERBOSE);

                StatisticsCollectorFacade::save();
            });
        } else {
            \Log::channel('websocket')->debug('Statistics disabled');
        }
    }

    /**
     * Configure the restart timer.
     *
     * @return void
     */
    public function configureRestartTimer()
    {
        $restartData = $this->getLastRestartData();
        $this->lastRestart = $restartData['time'] ?? null;

        \Log::channel('websocket')->debug('Restart timer configured', [
            'initial_restart_time' => $this->lastRestart,
        ]);

        $this->loop->addPeriodicTimer(10, function () {
            $restartData = $this->getLastRestartData();
            $currentRestart = $restartData['time'] ?? null;

            \Log::channel('websocket')->debug('Restart timer tick', [
                'last_restart' => $this->lastRestart,
                'current_restart' => $currentRestart,
            ]);

            // Only trigger restart if lastRestart was set and a new restart signal was received
            if ($this->lastRestart !== null && $currentRestart !== null && $currentRestart !== $this->lastRestart) {
                $this->restartSoftShutdown = $restartData['soft'] ?? false;

                \Log::channel('websocket')->info('Restart detected, triggering shutdown...', [
                    'previous_restart' => $this->lastRestart,
                    'current_restart' => $currentRestart,
                    'soft' => $this->restartSoftShutdown,
                ]);

                // Update lastRestart to prevent multiple triggers
                $this->lastRestart = $currentRestart;

                $this->triggerShutdown(true);
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
        \Log::channel('websocket')->debug('Registering WebSocket routes...');
        WebSocketRouter::registerRoutes();
        \Log::channel('websocket')->debug('WebSocket routes registered');
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
            \Log::channel('websocket')->error('pcntl extension not loaded');
            throw new \RuntimeException('The pcntl extension is required to handle concurrency.');
        }

        \Log::channel('websocket')->debug('pcntl extension loaded, registering signal handlers...');

        $this->loop->addSignal(SIGTERM, function () {
            \Log::channel('websocket')->info('Received SIGTERM, shutting down...');
            $this->line('Shutting down server...');

            $this->triggerShutdown();
        });
        \Log::channel('websocket')->debug('SIGTERM handler registered');

        $this->loop->addSignal(SIGINT, function () {
            \Log::channel('websocket')->info('Received SIGINT, shutting down...');
            $this->line('Shutting down server...');

            $this->triggerShutdown();
        });
        \Log::channel('websocket')->debug('SIGINT handler registered');
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
        // Log comprehensive startup information
        $this->logServerStartupInfo();

        \Log::channel('websocket')->info('Starting WebSocket server...', [
            'host' => $this->option('host'),
            'port' => $this->option('port'),
        ]);

        $this->components->info("Starting the WebSocket server on port {$this->option('port')}...");
        $this->comment('  <fg=yellow;options=bold>Press Ctrl+C to stop the server</>');
        $this->newLine();

        \Log::channel('websocket')->debug('Building server instance...');
        $this->buildServer();
        \Log::channel('websocket')->debug('Server instance built, starting event loop...');

        $this->server->run();
        \Log::channel('websocket')->debug('Event loop stopped, server shutdown complete');
    }

    /**
     * Log comprehensive server startup information
     */
    protected function logServerStartupInfo(): void
    {
        $divider = str_repeat('=', 60);

        // System info
        $phpVersion = PHP_VERSION;
        $phpSapi = PHP_SAPI;
        $os = PHP_OS;
        $arch = php_uname('m');

        // IPC Cache info
        $ipcUseTmpfs = IpcCache::isTmpfs();
        $ipcStatus = $ipcUseTmpfs ? '/dev/shm (RAM-backed)' : '/tmp (disk-backed)';

        // Socket pair IPC support
        $socketPairSupported = SocketPairIpc::isSupported();

        // Memory info
        $memoryLimit = ini_get('memory_limit');

        // ReactPHP loop type
        $loopClass = get_class($this->loop);

        // Extensions
        $extensions = [
            'pcntl' => extension_loaded('pcntl') ? 'enabled' : 'disabled',
            'posix' => extension_loaded('posix') ? 'enabled' : 'disabled',
            'sockets' => extension_loaded('sockets') ? 'enabled' : 'disabled',
            'ev' => extension_loaded('ev') ? 'enabled' : 'disabled',
            'event' => extension_loaded('event') ? 'enabled' : 'disabled',
            'uv' => extension_loaded('uv') ? 'enabled' : 'disabled',
        ];

        // IPC polling interval
        $ipcPollInterval = '2ms'; // From Handler::IPC_POLL_INTERVAL

        // Build startup message
        $startupInfo = [
            'php_version' => $phpVersion,
            'php_sapi' => $phpSapi,
            'os' => $os,
            'arch' => $arch,
            'memory_limit' => $memoryLimit,
            'ipc_storage' => $ipcStatus,
            'ipc_tmpfs' => $ipcUseTmpfs,
            'ipc_socket_pair' => $socketPairSupported,
            'ipc_poll_interval' => $ipcPollInterval,
            'event_loop' => $loopClass,
            'extensions' => $extensions,
            'pid' => getmypid(),
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'cache_driver' => $this->option('cache-driver'),
        ];

        // Log to file
        \Log::channel('websocket')->info($divider);
        \Log::channel('websocket')->info('WEBSOCKET SERVER STARTING');
        \Log::channel('websocket')->info($divider);
        \Log::channel('websocket')->info('PHP Version: ' . $phpVersion . ' (' . $phpSapi . ')');
        \Log::channel('websocket')->info('OS: ' . $os . ' (' . $arch . ')');
        \Log::channel('websocket')->info('Memory Limit: ' . $memoryLimit);
        \Log::channel('websocket')->info('Event Loop: ' . $loopClass);
        \Log::channel('websocket')->info('IPC Storage: ' . $ipcStatus);
        \Log::channel('websocket')->info('Socket Pair IPC: ' . ($socketPairSupported ? 'ENABLED (event-driven, no polling)' : 'disabled'));
        \Log::channel('websocket')->info('IPC Poll Fallback: ' . ($socketPairSupported ? 'not used' : $ipcPollInterval));
        \Log::channel('websocket')->info('Extensions: pcntl=' . $extensions['pcntl'] . ', sockets=' . $extensions['sockets'] . ', ev=' . $extensions['ev']);
        \Log::channel('websocket')->info('PID: ' . getmypid());
        \Log::channel('websocket')->info($divider);

        // Also output to console
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>PHP Version</>', $phpVersion . ' (' . $phpSapi . ')');
        $this->components->twoColumnDetail('<fg=cyan>OS</>', $os . ' (' . $arch . ')');
        $this->components->twoColumnDetail('<fg=cyan>Memory Limit</>', $memoryLimit);
        $this->components->twoColumnDetail('<fg=cyan>Event Loop</>', class_basename($loopClass));
        $this->components->twoColumnDetail(
            '<fg=cyan>IPC Storage</>',
            $ipcUseTmpfs ? '<fg=green>RAM-backed (/dev/shm)</>' : '<fg=yellow>Disk-backed (/tmp)</>'
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>Socket Pair IPC</>',
            $socketPairSupported ? '<fg=green>ENABLED (event-driven)</>' : '<fg=yellow>disabled (will poll)</>'
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>IPC Poll Fallback</>',
            $socketPairSupported ? '<fg=gray>not used</>' : '<fg=yellow>' . $ipcPollInterval . '</>'
        );
        $this->components->twoColumnDetail('<fg=cyan>PCNTL</>', $extensions['pcntl'] === 'enabled' ? '<fg=green>enabled</>' : '<fg=red>disabled</>');
        $this->components->twoColumnDetail('<fg=cyan>Sockets Extension</>', $extensions['sockets'] === 'enabled' ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>');
        $this->components->twoColumnDetail('<fg=cyan>EV Extension</>', $extensions['ev'] === 'enabled' ? '<fg=green>enabled (fast loop)</>' : '<fg=gray>not installed</>');
        $this->newLine();
    }

    /**
     * Build the server instance.
     *
     * @return void
     */
    protected function buildServer()
    {
        \Log::channel('websocket')->debug('Creating ServerFactory...', [
            'host' => $this->option('host'),
            'port' => $this->option('port'),
        ]);

        $this->server = new ServerFactory(
            $this->option('host'),
            $this->option('port')
        );

        if ($loop = $this->option('loop')) {
            \Log::channel('websocket')->debug('Using injected loop');
            $this->loop = $loop;
        }

        \Log::channel('websocket')->debug('Configuring server with loop and routes...');
        $this->server = $this->server
            ->setLoop($this->loop)
            ->withRoutes(WebSocketRouter::getRoutes())
            ->setConsoleOutput($this->output)
            ->createServer();

        \Log::channel('websocket')->debug('Server created and ready');
    }

    /**
     * Get the last restart data from cache.
     *
     * @return array
     */
    protected function getLastRestartData()
    {
        $data = Cache::get('blax:websockets:restart');

        // Handle legacy format (just timestamp) for backwards compatibility
        if (is_numeric($data)) {
            return ['time' => $data, 'soft' => false];
        }

        return $data ?? ['time' => null, 'soft' => false];
    }

    /**
     * Trigger shutdown based on the --soft option or restart signal.
     *
     * @return void
     */
    protected function triggerShutdown(bool $fromRestart = false)
    {
        // Check restart signal's soft flag first, then fall back to command option
        $useSoftShutdown = $fromRestart ? $this->restartSoftShutdown : $this->option('soft');

        \Log::channel('websocket')->debug('Triggering shutdown', [
            'from_restart' => $fromRestart,
            'use_soft_shutdown' => $useSoftShutdown,
        ]);

        if ($useSoftShutdown) {
            $this->triggerSoftShutdown();
        } else {
            $this->triggerHardShutdown();
        }
    }

    /**
     * Trigger a hard shutdown for the process.
     * Immediately stops the loop without gracefully closing connections.
     *
     * @return void
     */
    protected function triggerHardShutdown()
    {
        \Log::channel('websocket')->info('Triggering hard shutdown...');
        $this->line('Hard shutdown initiated, stopping server immediately...');

        $this->loop->stop();
    }

    /**
     * Trigger a soft shutdown for the process.
     * Gracefully closes all connections before stopping.
     *
     * @return void
     */
    protected function triggerSoftShutdown()
    {
        \Log::channel('websocket')->info('Triggering soft shutdown...');
        $this->line('Soft shutdown initiated, closing existing connections gracefully...');

        $channelManager = $this->laravel->make(ChannelManager::class);

        // Close the new connections allowance on this server.
        \Log::channel('websocket')->debug('Declining new connections...');
        $channelManager->declineNewConnections();

        // Get all local connections and close them. They will
        // be automatically be unsubscribed from all channels.
        \Log::channel('websocket')->debug('Getting local connections to close...');
        $channelManager->getLocalConnections()
            ->then(function ($connections) {
                \Log::channel('websocket')->debug('Closing connections...', ['count' => count($connections)]);
                return all(collect($connections)->map(function ($connection) {
                    return app('websockets.handler')
                        ->onClose($connection)
                        ->then(function () use ($connection) {
                            $connection->close();
                        });
                })->toArray());
            })
            ->then(function () {
                \Log::channel('websocket')->debug('All connections closed, stopping loop...');
                $this->loop->stop();
            });
    }
}
