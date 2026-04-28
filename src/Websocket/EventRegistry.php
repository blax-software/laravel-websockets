<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

use BlaxSoftware\LaravelWebSockets\Attributes\Websocket;
use ReflectionClass;
use ReflectionMethod;

/**
 * Discovers HTTP controller methods tagged with {@see Websocket} and
 * exposes them as a flat event-name → target map.
 *
 * Acts as the bridge between Laravel HTTP controllers and the websocket
 * dispatcher: {@see Controller::handle()} consults
 * {@see EventRegistry::resolve()} as a fallback when
 * {@see ControllerResolver::resolve()} returns null, so the same controller
 * method can serve both surfaces.
 *
 * The registry is cached per-process via static properties; call
 * {@see EventRegistry::clear()} from hot-reload paths.
 */
class EventRegistry
{
    /**
     * Map of event name → ['class' => ..., 'method' => ..., 'needAuth' => bool].
     *
     * @var array<string, array{class: class-string, method: string, needAuth: bool}>|null
     */
    private static ?array $map = null;

    /**
     * Directories to scan for HTTP controllers carrying the attribute.
     * Defaults to Laravel's standard `app/Http/Controllers/`; tests / non-
     * standard apps can override via {@see setSearchPaths()}.
     *
     * @var array<int|string, string>|null
     */
    private static ?array $searchPaths = null;

    /**
     * Override the directories scanned for tagged controllers.
     *
     * Accepts either:
     *  - a list of absolute paths (each defaults to the App\Http\Controllers
     *    namespace, with subdirectory namespacing inferred from the relative
     *    path), OR
     *  - a [path => namespace-prefix] map for explicit control.
     *
     * @param array<int|string, string> $paths
     */
    public static function setSearchPaths(array $paths): void
    {
        self::$searchPaths = $paths;
        self::$map = null;
    }

    /**
     * Look up the target for a websocket event name.
     *
     * @return array{class: class-string, method: string, needAuth: bool}|null
     */
    public static function resolve(string $event): ?array
    {
        return self::map()[$event] ?? null;
    }

    /**
     * Full event-name → target map. Lazily built on first access.
     *
     * @return array<string, array{class: class-string, method: string, needAuth: bool}>
     */
    public static function map(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        self::$map = [];

        foreach (self::candidateClasses() as [$class, $baseNamespace]) {
            self::indexClass($class, $baseNamespace);
        }

        return self::$map;
    }

    public static function clear(): void
    {
        self::$map = null;
    }

    /**
     * Derive the websocket event prefix from a controller FQCN — exact
     * inverse of {@see ControllerResolver}'s event-prefix → class algorithm.
     *
     *   App\Http\Controllers\Api\V1\FlightschoolController
     *     ↓ strip base namespace                        Api\V1\Flightschool(Controller)
     *     ↓ kebab each segment                          api / v1 / flightschool
     *     ↓ join with '-'                               api-v1-flightschool
     *
     * The resolver's reverse pass on `api-v1-flightschool` would try
     *   App\Websocket\Controllers\ApiV1FlightschoolController, then
     *   Api\V1FlightschoolController, then
     *   Api\V1\FlightschoolController.
     *
     * That last form matches our v1 layout exactly — so an event sent to
     * `api-v1-flightschool.index` ends up at the same controller
     * regardless of which side (registry or resolver) finds it first.
     */
    public static function eventPrefixFor(string $fqcn, string $baseNamespace = 'App\\Http\\Controllers\\'): string
    {
        $baseNamespace = rtrim($baseNamespace, '\\') . '\\';

        if (str_starts_with($fqcn, $baseNamespace)) {
            $relative = substr($fqcn, strlen($baseNamespace));
        } else {
            // Fallback: just the short class name
            $relative = ltrim(strrchr($fqcn, '\\') ?: $fqcn, '\\');
        }

        $segments = explode('\\', $relative);

        // Strip trailing "Controller" from the leaf segment
        $last = array_pop($segments);
        $last = preg_replace('/Controller$/', '', $last) ?? $last;

        if ($last === '') {
            // Defensive: class literally named "Controller" — fall back to
            // parent folder if any.
            $last = array_pop($segments) ?? 'controller';
        }

        $segments[] = $last;

        $kebabSegments = array_map(
            static fn(string $seg): string => strtolower(
                preg_replace('/([a-z\d])([A-Z])/', '$1-$2', $seg) ?? $seg
            ),
            $segments
        );

        return implode('-', array_filter($kebabSegments, static fn(string $s) => $s !== ''));
    }

    /**
     * Backwards-compatible alias for {@see eventPrefixFor()}.
     *
     * @deprecated Use {@see eventPrefixFor()} which understands folder structure.
     */
    public static function defaultPrefixFor(string $shortClassName): string
    {
        return self::eventPrefixFor($shortClassName, '');
    }

    private static function indexClass(string $class, string $baseNamespace): void
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (\Throwable) {
            return;
        }

        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return;
        }

        $autoPrefix = self::eventPrefixFor($class, $baseNamespace);
        $classPrefix = null;

        // Class-level attribute: applies prefix to every public method
        $classAttr = $reflection->getAttributes(Websocket::class)[0] ?? null;
        if ($classAttr) {
            /** @var Websocket $instance */
            $instance = $classAttr->newInstance();
            $classPrefix = $instance->prefix ?? $autoPrefix;

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isAbstract() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $event = $instance->event ?? ($classPrefix . '.' . $method->getName());
                self::$map[$event] = [
                    'class' => $class,
                    'method' => $method->getName(),
                    'needAuth' => $instance->needAuth,
                ];
            }
        }

        // Method-level attributes override or supplement the class-level map
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getAttributes(Websocket::class) as $attr) {
                /** @var Websocket $instance */
                $instance = $attr->newInstance();

                $prefix = $instance->prefix
                    ?? $classPrefix
                    ?? $autoPrefix;

                $event = $instance->event ?? ($prefix . '.' . $method->getName());

                self::$map[$event] = [
                    'class' => $class,
                    'method' => $method->getName(),
                    'needAuth' => $instance->needAuth,
                ];
            }
        }
    }

    /**
     * Yield [fully-qualified class name, base namespace] pairs for every PHP
     * file in each configured search path.
     *
     * The base namespace is what {@see eventPrefixFor()} strips before
     * deriving the kebab-case event prefix — so passing it through here
     * preserves folder context (e.g. `App\Http\Controllers\Api\V1\…` becomes
     * `api-v1-…` rather than just `…`).
     *
     * @return iterable<int, array{0: class-string, 1: string}>
     */
    private static function candidateClasses(): iterable
    {
        foreach (self::resolvedSearchPaths() as $base => $namespace) {
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            $namespace = rtrim($namespace, '\\') . '\\';

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = ltrim(str_replace($base, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $relativeClass = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);
                $className = $namespace . $relativeClass;

                if (class_exists($className, true)) {
                    yield [$className, $namespace];
                }
            }
        }
    }

    /**
     * @return array<string, string> base path → namespace
     */
    private static function resolvedSearchPaths(): array
    {
        if (self::$searchPaths !== null) {
            $out = [];
            foreach (self::$searchPaths as $key => $value) {
                if (is_int($key)) {
                    // List form: infer namespace from the path
                    $path = $value;
                    $namespace = self::inferNamespace($path);
                    $out[$path] = $namespace;
                } else {
                    // [path => namespace] form
                    $out[$key] = $value;
                }
            }
            return $out;
        }

        $base = function_exists('app_path')
            ? app_path('Http/Controllers')
            : (defined('\\BASE_PATH') ? constant('\\BASE_PATH') . '/app/Http/Controllers' : null);

        if (! $base) {
            return [];
        }

        return [$base => 'App\\Http\\Controllers\\'];
    }

    /**
     * Best-effort PSR-4 namespace inference: anything under the Laravel
     * `app/` directory becomes `App\<RelativePath>\`.
     */
    private static function inferNamespace(string $path): string
    {
        if (! function_exists('app_path')) {
            return 'App\\';
        }

        try {
            $appPath = app_path();
        } catch (\Throwable) {
            return 'App\\';
        }

        $appPath = rtrim($appPath, DIRECTORY_SEPARATOR);
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if (! str_starts_with($path, $appPath)) {
            return 'App\\';
        }

        $relative = ltrim(substr($path, strlen($appPath)), DIRECTORY_SEPARATOR);
        $segments = $relative === '' ? [] : explode(DIRECTORY_SEPARATOR, $relative);

        return 'App\\' . (empty($segments) ? '' : implode('\\', $segments) . '\\');
    }
}
