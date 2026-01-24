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
     * App controller namespace
     */
    private const APP_NAMESPACE = '\\App\\Websocket\\Controllers\\';

    /**
     * Package controller namespace
     */
    private const VENDOR_NAMESPACE = '\\BlaxSoftware\\LaravelWebSockets\\Websocket\\Controllers\\';

    /**
     * Resolve controller class for an event prefix
     *
     * @param string $eventPrefix The event prefix (e.g., 'app', 'admin-user', 'admin-user-settings')
     * @return string|null The fully qualified controller class name, or null if not found
     */
    public static function resolve(string $eventPrefix): ?string
    {
        // Check cache first (O(1) lookup)
        if (array_key_exists($eventPrefix, self::$controllerCache)) {
            return self::$controllerCache[$eventPrefix];
        }

        // Ensure controllers are scanned
        if (!self::$scanned) {
            self::scanControllers();
        }

        // Try to find the controller
        $controllerClass = self::findController($eventPrefix);

        // Cache the result (even if null, to avoid repeated lookups)
        self::$controllerCache[$eventPrefix] = $controllerClass;

        return $controllerClass;
    }

    /**
     * Find controller using multiple strategies
     */
    private static function findController(string $eventPrefix): ?string
    {
        // Strategy 1: Direct match (e.g., 'app' → 'AppController')
        $directName = self::kebabToPascal($eventPrefix) . 'Controller';
        if ($class = self::findInAvailable($directName)) {
            return $class;
        }

        // Strategy 2: Folder structure (e.g., 'admin-user' → 'Admin/UserController')
        $parts = explode('-', $eventPrefix);
        if (count($parts) > 1) {
            // Try progressively deeper folder structures
            // 'admin-user-settings' tries:
            //   - Admin/User/SettingsController
            //   - Admin/UserSettingsController
            //   - AdminUser/SettingsController

            for ($folderDepth = count($parts) - 1; $folderDepth >= 1; $folderDepth--) {
                $folderParts = array_slice($parts, 0, $folderDepth);
                $nameParts = array_slice($parts, $folderDepth);

                $folder = implode('/', array_map('ucfirst', $folderParts));
                $name = implode('', array_map('ucfirst', $nameParts)) . 'Controller';

                // Try app namespace with folder
                $appClass = self::APP_NAMESPACE . str_replace('/', '\\', $folder) . '\\' . $name;
                if (class_exists($appClass)) {
                    self::$availableControllers[strtolower($name)] = $appClass;
                    return $appClass;
                }

                // Try vendor namespace with folder
                $vendorClass = self::VENDOR_NAMESPACE . str_replace('/', '\\', $folder) . '\\' . $name;
                if (class_exists($vendorClass)) {
                    self::$availableControllers[strtolower($name)] = $vendorClass;
                    return $vendorClass;
                }
            }
        }

        // Strategy 3: Dynamic class_exists check (for newly added controllers)
        $appClass = self::APP_NAMESPACE . $directName;
        if (class_exists($appClass)) {
            self::$availableControllers[strtolower($directName)] = $appClass;
            return $appClass;
        }

        $vendorClass = self::VENDOR_NAMESPACE . $directName;
        if (class_exists($vendorClass)) {
            self::$availableControllers[strtolower($directName)] = $vendorClass;
            return $vendorClass;
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
    }

    /**
     * Get cache statistics (for debugging)
     *
     * @return array{cached: int, available: int, scanned: bool}
     */
    public static function getStats(): array
    {
        return [
            'cached' => count(self::$controllerCache),
            'available' => count(self::$availableControllers),
            'scanned' => self::$scanned,
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
