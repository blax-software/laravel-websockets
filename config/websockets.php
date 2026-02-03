<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hot Reload (Development Mode)
    |--------------------------------------------------------------------------
    |
    | When enabled, ALL code is reloaded on every request in child processes.
    | This includes Models, Resources, Services, Controllers, Config, and
    | everything else - allowing code changes without restarting the server.
    |
    | How it works:
    | - OPcache is cleared in child processes (forces PHP to recompile files)
    | - Laravel container singletons are reset (forces fresh instantiation)
    | - Config files are re-read from disk
    | - View, route, translation, and validation caches are cleared
    | - WebSocket ControllerResolver cache is cleared
    |
    | WARNING: Disable in production for better performance. Hot reload adds
    | ~5-15ms overhead per request due to cache clearing and file re-reads.
    |
    */
    'hot_reload' => env('WEBSOCKET_HOT_RELOAD', env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Socket Settings
    |--------------------------------------------------------------------------
    |
    | The broadcast socket allows external PHP processes (queue workers, HTTP
    | requests, etc.) to send broadcasts to WebSocket clients efficiently via
    | a Unix domain socket, without the overhead of creating new connections.
    |
    | This provides global helper functions:
    | - ws_broadcast($event, $data, $channel) - Broadcast to all clients
    | - ws_whisper($event, $data, $sockets, $channel) - Send to specific sockets
    | - ws_broadcast_except($event, $data, $exclude, $channel) - Broadcast except some
    | - ws_available() - Check if broadcast socket is available
    |
    */
    'broadcast_socket_enabled' => env('WEBSOCKET_BROADCAST_SOCKET', true),
    'broadcast_socket' => env('WEBSOCKET_BROADCAST_SOCKET_PATH', '/tmp/laravel-websockets-broadcast.sock'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Settings
    |--------------------------------------------------------------------------
    |
    | You can configure the dashboard settings from here.
    |
    */
    'port' => env('LARAVEL_WEBSOCKETS_PORT', env('PUSHER_PORT', 6001)),

    'dashboard' => [
        'port' => env('LARAVEL_WEBSOCKETS_PORT', env('PUSHER_PORT', 6001)),
        'domain' => env('LARAVEL_WEBSOCKETS_DOMAIN'),
        'path' => env('LARAVEL_WEBSOCKETS_PATH', 'laravel-websockets'),
        'middleware' => [
            'web',
            \BlaxSoftware\LaravelWebSockets\Dashboard\Http\Middleware\Authorize::class,
        ],
    ],

    'managers' => [
        /*
        |--------------------------------------------------------------------------
        | Application Manager
        |--------------------------------------------------------------------------
        |
        | An Application manager determines how your websocket server allows
        | the use of the TCP protocol based on, for example, a list of allowed
        | applications.
        | By default, it uses the defined array in the config file, but you can
        | choose to use SQLite or MySQL application managers, or define a
        | custom application manager.
        |
        */
        'app' => \BlaxSoftware\LaravelWebSockets\Apps\ConfigAppManager::class,

        /*
        |--------------------------------------------------------------------------
        | SQLite application manager
        |--------------------------------------------------------------------------
        |
        | The SQLite database to use when using the SQLite application manager.
        |
        */
        'sqlite' => [
            'database' => storage_path('laravel-websockets.sqlite'),
        ],

        /*
        |--------------------------------------------------------------------------
        | MySql application manager
        |--------------------------------------------------------------------------
        |
        | The MySQL database connection to use.
        |
        */
        'mysql' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
            'table' => 'websockets_apps',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Applications Repository
    |--------------------------------------------------------------------------
    |
    | By default, the only allowed app is the one you define with
    | your PUSHER_* variables from .env.
    | You can configure to use multiple apps if you need to, or use
    | a custom App Manager that will handle the apps from a database, per se.
    |
    | You can apply multiple settings, like the maximum capacity, enable
    | client-to-client messages or statistics.
    |
    */
    'apps' => [
        [
            'id' => env('PUSHER_APP_ID'),
            'name' => env('APP_NAME'),
            'host' => env('PUSHER_APP_HOST'),
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'path' => env('PUSHER_APP_PATH'),
            'capacity' => null,
            'enable_client_messages' => true,
            'enable_statistics' => false,
            'allowed_origins' => [
                // env('LARAVEL_WEBSOCKETS_DOMAIN'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting Replication PubSub
    |--------------------------------------------------------------------------
    |
    | You can enable replication to publish and subscribe to
    | messages across the driver.
    |
    | By default, it is set to 'local', but you can configure it to use drivers
    | like Redis to ensure connection between multiple instances of
    | WebSocket servers. Just set the driver to 'redis' to enable the PubSub using Redis.
    |
    */

    'replication' => [

        'mode' => env('WEBSOCKETS_REPLICATION_MODE', 'custom'),

        'modes' => [
            'local' => [
                'channel_manager' => \BlaxSoftware\LaravelWebSockets\ChannelManagers\LocalChannelManager::class,
                'collector' => \BlaxSoftware\LaravelWebSockets\Statistics\Collectors\MemoryCollector::class,
            ],

            'redis' => [
                'connection' => env('WEBSOCKETS_REDIS_REPLICATION_CONNECTION', 'default'),
                'channel_manager' => \BlaxSoftware\LaravelWebSockets\ChannelManagers\RedisChannelManager::class,
                'collector' => \BlaxSoftware\LaravelWebSockets\Statistics\Collectors\RedisCollector::class,
            ],

            'custom' => [
                'connection' => env('WEBSOCKETS_REDIS_REPLICATION_CONNECTION', 'default'),
                'channel_manager' => \BlaxSoftware\LaravelWebSockets\Websocket\ChannelManager::class,
                'collector' => BlaxSoftware\LaravelWebSockets\Statistics\Collectors\MemoryCollector::class,
            ],
        ],
    ],

    'statistics' => [

        /*
        |--------------------------------------------------------------------------
        | Statistics Store
        |--------------------------------------------------------------------------
        |
        | The Statistics Store is the place where all the temporary stats will
        | be dumped. This is a much reliable store and will be used to display
        | graphs or handle it later on your app.
        |
        */

        'store' => \BlaxSoftware\LaravelWebSockets\Statistics\Stores\DatabaseStore::class,

        /*
        |--------------------------------------------------------------------------
        | Statistics Interval Period
        |--------------------------------------------------------------------------
        |
        | Here you can specify the interval in seconds at which
        | statistics should be logged.
        |
        */

        'interval_in_seconds' => 60,

        /*
        |--------------------------------------------------------------------------
        | Statistics Deletion Period
        |--------------------------------------------------------------------------
        |
        | When the clean-command is executed, all recorded statistics older than
        | the number of days specified here will be deleted.
        |
        */

        'delete_statistics_older_than_days' => 7,

    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Request Size
    |--------------------------------------------------------------------------
    |
    | The maximum request size in kilobytes that is allowed for
    | an incoming WebSocket request.
    |
    */

    'max_request_size_in_kb' => 2048,

    /*
    |--------------------------------------------------------------------------
    | SSL Configuration
    |--------------------------------------------------------------------------
    |
    | By default, the configuration allows only on HTTP. For SSL, you need
    | to set up the the certificate, the key, and optionally, the passphrase
    | for the private key.
    | You will need to restart the server for the settings to take place.
    |
    */

    'ssl' => [
        'local_cert' => env('LARAVEL_WEBSOCKETS_SSL_LOCAL_CERT', null),
        'capath' => env('LARAVEL_WEBSOCKETS_SSL_CA', null),
        'local_pk' => env('LARAVEL_WEBSOCKETS_SSL_LOCAL_PK', null),
        'passphrase' => env('LARAVEL_WEBSOCKETS_SSL_PASSPHRASE', null),
        'verify_peer' => env('APP_ENV') === 'production',
        'allow_self_signed' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Handlers
    |--------------------------------------------------------------------------
    |
    | Here you can specify the route handlers that will take over
    | the incoming/outgoing websocket connections. You can extend the
    | original class and implement your own logic, alongside
    | with the existing logic.
    |
    */

    'handlers' => [

        'websocket' => \BlaxSoftware\LaravelWebSockets\Websocket\Handler::class,

        'health' => \BlaxSoftware\LaravelWebSockets\Server\HealthHandler::class,

        'trigger_event' => \BlaxSoftware\LaravelWebSockets\API\TriggerEvent::class,

        'fetch_channels' => \BlaxSoftware\LaravelWebSockets\API\FetchChannels::class,

        'fetch_channel' => \BlaxSoftware\LaravelWebSockets\API\FetchChannel::class,

        'fetch_users' => \BlaxSoftware\LaravelWebSockets\API\FetchUsers::class,

    ],

    /*
    |--------------------------------------------------------------------------
    | Promise Resolver
    |--------------------------------------------------------------------------
    |
    | The promise resolver is a class that takes a input value and is
    | able to make sure the PHP code runs async by using ->then(). You can
    | use your own Promise Resolver. This is usually changed when you want to
    | intercept values by the promises throughout the app, like in testing
    | to switch from async to sync.
    |
    */

    'promise_resolver' => \React\Promise\FulfilledPromise::class,

];
