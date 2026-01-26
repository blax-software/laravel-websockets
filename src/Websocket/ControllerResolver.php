<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Websocket;

/**
 * Resolves WebSocket controller classes with caching and fuzzy folder matching.
 *
 * Supports:
 * - Flat controllers: `app.method` → `AppController`
 * - Kebab-case: `admin-user.method` → `AdminUserController`
 * - Folder structure: `admin-user.method` → `Admin/UserController` (fuzzy)
 * - Dynamic discovery: new controllers are found and cached at runtime
 * - Hot reload: disable caching in dev mode for instant code updates
 */
class ControllerResolver
{
    /**
     * In-memory cache of event prefix → controller class mappings
     * This persists for the lifetime of the WebSocket server process
     *
     * @var array<string, string|null>
     */
    private static array $controllerCache = [];

    /**
     * Pre-scanned controller paths for fast lookup
     * Maps lowercase class name → actual class name with namespace
     *
     * @var array<string, string>
     */
    private static array $availableControllers = [];

    /**
     * Whether controllers have been pre-scanned
     */
    private static bool $scanned = false;

    /**
     * Hot reload mode - when enabled, caching is disabled for development
     */
    private static ?bool $hotReload = null;

    /**
     * Track when classes were loaded to detect stale code
     * Maps class name => file mtime at load time
     *
     * @var array<string, int>
     */
    private static array $classLoadTimes = [];

    /**
     * App controller namespace
     */
    private const APP_NAMESPACE = '\\App\\Websocket\\Controllers\\';

    /**
     * Package controller namespace
     */
    private const VENDOR_NAMESPACE = '\\BlaxSoftware\\LaravelWebSockets\\Websocket\\Controllers\\';

    /**
     * Check if hot reload mode is enabled
     */
    private static function isHotReload(): bool
    {
        if (self::$hotReload === null) {
            self::$hotReload = (bool) config('websockets.hot_reload', false);
        }
        return self::$hotReload;
    }

    /**
     * Resolve controller class for an event prefix
     *
     * @param string $eventPrefix The event prefix (e.g., 'app', 'admin-user', 'admin-user-settings')
     * @return string|null The fully qualified controller class name, or null if not found
     */
    public static function resolve(string $eventPrefix): ?string
    {
        // In hot reload mode, skip cache and invalidate opcache for fresh code
        if (self::isHotReload()) {
            return self::resolveWithHotReload($eventPrefix);
        }

        // Check cache first (O(1) lookup)
        if (array_key_exists($eventPrefix, self::$controllerCache)) {
            return self::$controllerCache[$eventPrefix];
        }

        // Try to find the controller (skip scanning in forked children - classes are already loaded)
        $controllerClass = self::findController($eventPrefix);

        // Cache the result (even if null, to avoid repeated lookups)
        self::$controllerCache[$eventPrefix] = $controllerClass;

        return $controllerClass;
    }

    /**
     * Resolve controller with hot reload - invalidates opcache for fresh code
     * This is slower but allows code changes without server restart
     */
    private static function resolveWithHotReload(string $eventPrefix): ?string
    {
        $directName = self::kebabToPascal($eventPrefix) . 'Controller';
        
        // Try app namespace first
        $appClass = self::APP_NAMESPACE . $directName;
        $appFile = self::getControllerFilePath($appClass);
        
        if ($appFile && file_exists($appFile)) {
            self::invalidateAndReload($appFile);
            if (class_exists($appClass, true)) {
                return $appClass;
            }
        }

        // Try vendor namespace
        $vendorClass = self::VENDOR_NAMESPACE . $directName;
        $vendorFile = self::getControllerFilePath($vendorClass);
        
        if ($vendorFile && file_exists($vendorFile)) {
            self::invalidateAndReload($vendorFile);
            if (class_exists($vendorClass, true)) {
                return $vendorClass;
            }
        }

        // Try folder structure for kebab-case names
        $parts = explode('-', $eventPrefix);
        if (count($parts) > 1) {
            for ($folderDepth = count($parts) - 1; $folderDepth >= 1; $folderDepth--) {
                $folderParts = array_slice($parts, 0, $folderDepth);
                $nameParts = array_slice($parts, $folderDepth);

                $folder = implode('/', array_map('ucfirst', $folderParts));
                $name = implode('', array_map('ucfirst', $nameParts)) . 'Controller';

                // Try app namespace with folder
                $appClass = self::APP_NAMESPACE . str_replace('/', '\\', $folder) . '\\' . $name;
                $appFile = self::getControllerFilePath($appClass);
                
                if ($appFile && file_exists($appFile)) {
                    self::invalidateAndReload($appFile);
                    if (class_exists($appClass, true)) {
                        return $appClass;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Invalidate opcache for a file and force reload
     */
    private static function invalidateAndReload(string $filePath): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($filePath, true);
        }
    }

    /**
     * Get the file path for a controller class
     */
    private static function getControllerFilePath(string $className): ?string
    {
        // For App namespace
        if (str_starts_with($className, self::APP_NAMESPACE)) {
            $relativePath = str_replace(self::APP_NAMESPACE, '', $className);
            $relativePath = str_replace('\\', '/', $relativePath);
            $appPath = self::getAppControllersPath();
            if ($appPath) {
                return $appPath . '/' . $relativePath . '.php';
            }
        }
        
        // For vendor namespace
        if (str_starts_with($className, self::VENDOR_NAMESPACE)) {
            $relativePath = str_replace(self::VENDOR_NAMESPACE, '', $className);
            $relativePath = str_replace('\\', '/', $relativePath);
            return __DIR__ . '/Controllers/' . $relativePath . '.php';
        }

        return null;
    }

    /**
     * Find controller using multiple strategies
     * Optimized for speed: most common case (direct match) checked first
     */
    private static function findController(string $eventPrefix): ?string
    {
        // Strategy 1: Direct match in app namespace (most common case)
        // e.g., 'app' → '\App\Websocket\Controllers\AppController'
        $directName = self::kebabToPascal($eventPrefix) . 'Controller';
        $appClass = self::APP_NAMESPACE . $directName;
        
        // class_exists with autoload=true is fast for already-loaded classes
        if (class_exists($appClass, true)) {
            return $appClass;
        }

        // Strategy 2: Direct match in vendor namespace
        $vendorClass = self::VENDOR_NAMESPACE . $directName;
        if (class_exists($vendorClass, true)) {
            return $vendorClass;
        }

        // Strategy 3: Check pre-scanned available controllers (if scanned)
        if (self::$scanned) {
            if ($class = self::findInAvailable($directName)) {
                return $class;
            }
        }

        // Strategy 4: Folder structure (e.g., 'admin-user' → 'Admin/UserController')
        // Only try this for kebab-case names with multiple parts
        $parts = explode('-', $eventPrefix);
        if (count($parts) > 1) {
            for ($folderDepth = count($parts) - 1; $folderDepth >= 1; $folderDepth--) {
                $folderParts = array_slice($parts, 0, $folderDepth);
                $nameParts = array_slice($parts, $folderDepth);

                $folder = implode('\\', array_map('ucfirst', $folderParts));
                $name = implode('', array_map('ucfirst', $nameParts)) . 'Controller';

                // Try app namespace with folder
                $appClass = self::APP_NAMESPACE . $folder . '\\' . $name;
                if (class_exists($appClass, true)) {
                    return $appClass;
                }

                // Try vendor namespace with folder
                $vendorClass = self::VENDOR_NAMESPACE . $folder . '\\' . $name;
                if (class_exists($vendorClass, true)) {
                    return $vendorClass;
                }
            }
        }

        return null;
    }

    /**
     * Find a controller in the pre-scanned available controllers
     */
    private static function findInAvailable(string $controllerName): ?string
    {
        $key = strtolower($controllerName);
        return self::$availableControllers[$key] ?? null;
    }

    /**
     * Convert kebab-case to PascalCase
     * 'admin-user-settings' → 'AdminUserSettings'
     */
    private static function kebabToPascal(string $kebab): string
    {
        return implode('', array_map('ucfirst', explode('-', $kebab)));
    }

    /**
     * Pre-scan all controller directories and cache the available controllers
     * This is called once at server startup or on first request
     */
    public static function scanControllers(): void
    {
        if (self::$scanned) {
            return;
        }

        // Scan app controllers (including subfolders)
        $appPath = self::getAppControllersPath();
        if ($appPath && is_dir($appPath)) {
            self::scanDirectory($appPath, self::APP_NAMESPACE);
        }

        // Scan vendor controllers
        $vendorPath = __DIR__ . '/Controllers';
        if (is_dir($vendorPath)) {
            self::scanDirectory($vendorPath, self::VENDOR_NAMESPACE);
        }

        self::$scanned = true;
    }

    /**
     * Recursively scan a directory for controller classes
     */
    private static function scanDirectory(string $path, string $namespace, string $subNamespace = ''): void
    {
        $iterator = new \DirectoryIterator($path);

        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                // Recurse into subdirectory
                $folderName = $item->getFilename();
                $newSubNamespace = $subNamespace . $folderName . '\\';
                self::scanDirectory($item->getPathname(), $namespace, $newSubNamespace);
            } elseif ($item->isFile() && $item->getExtension() === 'php') {
                $fileName = $item->getBasename('.php');

                // Only consider files ending with 'Controller'
                if (!str_ends_with($fileName, 'Controller')) {
                    continue;
                }

                $fullClass = $namespace . $subNamespace . $fileName;

                // Verify the class exists (triggers autoload)
                if (class_exists($fullClass, true)) {
                    // Store with lowercase key for case-insensitive lookup
                    $key = strtolower($fileName);
                    self::$availableControllers[$key] = $fullClass;

                    // Also store with folder prefix for folder-based lookup
                    if ($subNamespace) {
                        $folderKey = strtolower(str_replace('\\', '', $subNamespace) . $fileName);
                        self::$availableControllers[$folderKey] = $fullClass;
                    }
                }
            }
        }
    }

    /**
     * Get the app controllers path
     */
    private static function getAppControllersPath(): ?string
    {
        // Try Laravel's app_path if the application is fully booted
        if (function_exists('app_path')) {
            try {
                $path = app_path('Websocket/Controllers');
                if (is_dir($path)) {
                    return $path;
                }
            } catch (\Throwable $e) {
                // app_path() might fail if app isn't booted - fall through to fallback
            }
        }

        // Fallback: try common locations
        $basePaths = [
            defined('BASE_PATH') ? BASE_PATH : null,
            getcwd(),
            dirname(__DIR__, 5),  // Go up from vendor package
            dirname(__DIR__, 4),
        ];

        foreach (array_filter($basePaths) as $basePath) {
            $path = $basePath . '/app/Websocket/Controllers';
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Clear the controller cache (useful for testing or hot reload)
     */
    public static function clearCache(): void
    {
        self::$controllerCache = [];
        self::$availableControllers = [];
        self::$scanned = false;
        self::$hotReload = null;
    }

    /**
     * Get cache statistics (for debugging)
     *
     * @return array{cached: int, available: int, scanned: bool, hot_reload: bool}
     */
    public static function getStats(): array
    {
        return [
            'cached' => count(self::$controllerCache),
            'available' => count(self::$availableControllers),
            'scanned' => self::$scanned,
            'hot_reload' => self::isHotReload(),
        ];
    }

    /**
     * Force preload of all controllers (call at server startup)
     */
    public static function preload(): void
    {
        self::scanControllers();
    }
}
