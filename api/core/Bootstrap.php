<?php
class Bootstrap {
    private static $bootstrapped = false;

    public static function init() {
        if (self::$bootstrapped) {
            return;
        }

        // Load environment first
        require_once __DIR__ . '/../config/Environment.php';
        Environment::load();

        // Set error reporting
        self::configureErrorReporting();

        // Initialize core components
        self::initializeCoreComponents();

        // Configure CORS
        self::configureCORS();

        self::$bootstrapped = true;
        
        Logger::debug("Bootstrap completed successfully");
    }

    private static function configureErrorReporting() {
        if (Environment::isDevelopment()) {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 0);
            ini_set('display_startup_errors', 0);
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        }
    }

    private static function initializeCoreComponents() {
        // Load composer autoload first
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }

        // Register autoloader
        require_once __DIR__ . '/Autoloader.php';
        Autoloader::register();

        // Initialize logger
        Logger::initialize();
        Logger::logRequest();

        // Register error handler
        ErrorHandler::register();
    }

    private static function configureCORS() {
        // Skip CORS configuration in CLI environment
        if (php_sapi_name() === 'cli') {
            return;
        }

        $allowed_origins = Environment::getAllowedOrigins();

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, $allowed_origins)) {
            header("Access-Control-Allow-Origin: " . $origin);
        } else {
            // Use first allowed origin as default
            header("Access-Control-Allow-Origin: " . $allowed_origins[0]);
        }

        header("Content-Type: application/json; charset=UTF-8");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Max-Age: 3600");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: true");

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            Logger::debug("Handling preflight OPTIONS request");
            exit(0);
        }
    }

    public static function isBootstrapped() {
        return self::$bootstrapped;
    }
}
?>
