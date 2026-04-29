<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Routing;

use BlaxSoftware\LaravelWebSockets\Websocket\EventRegistry;
use Illuminate\Routing\Router;

/**
 * Registers every websocket event as a Laravel route with method `WS|WSS`
 * so the full WS surface shows up in `php artisan route:list` next to the
 * regular HTTP endpoints.
 *
 * The routes are inert for HTTP dispatch — Laravel's router will never
 * match a real HTTP request against the `WS`/`WSS` methods — but they
 * appear in `route:list`, `route:cache`, and similar tooling, which is
 * the whole point: a developer wanting to enumerate the realtime API no
 * longer has to grep `App\Websocket\Controllers\` by hand.
 *
 * Two sources are merged into one list:
 *   1. Legacy WS controllers under `App\Websocket\Controllers\`. Every
 *      public, declared, non-lifecycle method becomes one event using
 *      the same kebab-prefix algorithm `ControllerResolver` resolves.
 *   2. Attribute-tagged HTTP controller methods (`#[Websocket]`) via
 *      {@see EventRegistry}.
 *
 * Conflicts between the two sources are resolved with the HTTP-attribute
 * winning (last write), since the attribute is the explicit declaration
 * by the developer.
 */
class RouteListInjector
{
    /** Methods on the package's WS Controller base class that aren't real events. */
    private const LIFECYCLE_METHODS = [
        'boot', 'booted', 'unboot',
        'error', 'success',
        'getConnection', 'getChannel', 'getEvent', 'getChannelManager',
        'controll_message', 'handle',
        'send_error',
    ];

    /**
     * Register every discovered WS event as a `WS|WSS` route on $router.
     */
    public static function inject(Router $router): void
    {
        foreach (self::collect() as $event => $target) {
            $router->addRoute(
                ['WS', 'WSS'],
                $event,
                ['uses' => $target['class'] . '@' . $target['method']]
            );
        }
    }

    /**
     * Build a sorted event-name → target map merged from both sources.
     *
     * @return array<string, array{class: class-string, method: string, source: string, needAuth: bool}>
     */
    public static function collect(): array
    {
        $events = [];

        // 1. Legacy `App\Websocket\Controllers\*` controllers
        foreach (self::scanLegacyControllers() as $class => $methods) {
            $prefix = EventRegistry::eventPrefixFor($class, 'App\\Websocket\\Controllers\\');

            // The `$need_auth` property defaults to true on the base controller
            // unless the subclass overrides it to false (see existing pattern
            // on `*GuestController`). Use a static reflection read so we can
            // surface auth status in route:list.
            $needAuth = self::classNeedsAuth($class);

            foreach ($methods as $method) {
                $events[$prefix . '.' . $method] = [
                    'class' => $class,
                    'method' => $method,
                    'source' => 'ws-controller',
                    'needAuth' => $needAuth,
                ];
            }
        }

        // 2. Attribute-tagged HTTP controllers — these win on collision
        foreach (EventRegistry::map() as $event => $target) {
            $events[$event] = [
                'class' => $target['class'],
                'method' => $target['method'],
                'source' => 'http-attribute',
                'needAuth' => $target['needAuth'],
            ];
        }

        ksort($events);
        return $events;
    }

    /**
     * Scan App\Websocket\Controllers/ recursively for controllers and their
     * public, declared, non-lifecycle methods.
     *
     * @return array<class-string, array<int, string>>
     */
    private static function scanLegacyControllers(): array
    {
        if (! function_exists('app_path')) {
            return [];
        }

        try {
            $base = app_path('Websocket/Controllers');
        } catch (\Throwable) {
            return [];
        }

        if (! is_dir($base)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($base, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $className = 'App\\Websocket\\Controllers\\' . str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

            if (! class_exists($className, true)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
            } catch (\Throwable) {
                continue;
            }

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $methods = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->isAbstract()) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }
                if (in_array($method->getName(), self::LIFECYCLE_METHODS, true)) {
                    continue;
                }

                $methods[] = $method->getName();
            }

            if ($methods) {
                $found[$className] = $methods;
            }
        }

        return $found;
    }

    private static function classNeedsAuth(string $class): bool
    {
        try {
            $reflection = new \ReflectionClass($class);
        } catch (\Throwable) {
            return true;
        }

        if (! $reflection->hasProperty('need_auth')) {
            return true; // base default
        }

        $prop = $reflection->getProperty('need_auth');

        // Try to read default value (works whether the property is public
        // or protected, because we're reading the declared default, not
        // an instance value).
        $defaults = $reflection->getDefaultProperties();
        if (array_key_exists('need_auth', $defaults)) {
            return (bool) $defaults['need_auth'];
        }

        return true;
    }
}
