<?php

declare(strict_types=1);

/**
 * Ocean View Flats - Administrative Interface Front-Controller
 * Subdomain: admin.oceanviewflats.com
 * Isolation: Dedicated front-controller entry point per ADR 0005
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OceanViewFlats\Admin\AdminApp;
use OceanViewFlats\Admin\Config\ConfigPathResolver;
use OceanViewFlats\Admin\Db\DatabaseFactory;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;

try {
    // 1. Initialize Subdomain-Isolated Native Session
    SessionMiddleware::startNativeSession();

    // 2. Load Core Configuration & Establish PDO Connection
    $root = dirname(__DIR__, 2);
    $configPath = ConfigPathResolver::resolveConfigPath($root);
    /** @var array{db: array{host?: string, dbname?: string, user?: string, pass?: string}} $config */
    $config = require $configPath;
    $pdo = DatabaseFactory::createConnection($config['db']);

    // 3. Initialize Authoritative Application Kernel
    $app = AdminApp::createDefault($pdo);

    // 4. Capture Request & Dispatch via Application Kernel
    $request = Request::fromGlobals();
    $response = $app->handle($request, $_SESSION);
    $response->send();
} catch (Throwable $e) {
    error_log("[Admin Front-Controller Critical Error] " . $e->getMessage() . "\n" . $e->getTraceAsString());

    $isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';
    $errorMessage = $isProduction ? 'A system error occurred. Please contact the administrator.' : htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

    $errorResponse = Response::html(
        '<div class="min-h-screen bg-gray-50 flex items-center justify-center p-6"><div class="max-w-md w-full bg-white p-8 rounded-xl shadow border border-rose-100 text-center"><h1 class="text-xl font-bold text-rose-700">500 Server Error</h1><p class="text-gray-600 mt-2 text-sm">' . $errorMessage . '</p></div></div>',
        500
    );
    $errorResponse->send();
}
