<?php

declare(strict_types=1);

/**
 * Ocean View Flats - Administrative Interface Front-Controller
 * Subdomain: admin.oceanviewflats.com
 * Isolation: Dedicated front-controller entry point per ADR 0005
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\FileIpRateLimiter;
use OceanViewFlats\Admin\Controller\AuthController;
use OceanViewFlats\Admin\Controller\CalendarBlockController;
use OceanViewFlats\Admin\Controller\DashboardController;
use OceanViewFlats\Admin\Controller\RateController;
use OceanViewFlats\Admin\Controller\ReservationController;
use OceanViewFlats\Admin\Db\DatabaseFactory;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Repository\AdminCalendarBlockRepository;
use OceanViewFlats\Admin\Repository\AdminRateRepository;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Service\MercadoPagoRefundClient;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\PhpMailSender;
use OceanViewFlats\Domain\Quote\CsvRateSource;
use OceanViewFlats\Domain\Quote\PdoRateSource;
use OceanViewFlats\Domain\Quote\PropertyRatesConfig;
use OceanViewFlats\Domain\Quote\QuoteEngine;
use OceanViewFlats\Domain\Reservation\ReservationLedger;

try {
    // 1. Initialize Subdomain-Isolated Native Session
    SessionMiddleware::startNativeSession();

    // 2. Load Core Configuration & Establish PDO Connection
    /** @var array{db: array{host?: string, dbname?: string, user?: string, pass?: string}} $config */
    $config = require dirname(__DIR__, 2) . '/public/api/config.php';
    $pdo = DatabaseFactory::createConnection($config['db']);
    AuditLogger::setDefaultPdo($pdo);
    $auditLogger = new AuditLogger($pdo);

    // 3. Assemble Dependencies & Controllers
    $rateLimiter = FileIpRateLimiter::createDefault();
    $authService = new AuthService($pdo, $rateLimiter);
    $viewRenderer = new ViewRenderer(dirname(__DIR__) . '/src/Views');

    $authController = new AuthController(
        auditLogger: $auditLogger,
        authService: $authService,
        viewRenderer: $viewRenderer
    );
    $dashboardController = new DashboardController(
        viewRenderer: $viewRenderer
    );
    $reservationRepo = new AdminReservationRepository($pdo);
    $publicSiteUrl = getenv('PUBLIC_SITE_URL') ?: 'https://oceanviewflats.com';
    $ratesConfig = PropertyRatesConfig::createDefault();
    $rateSource = new PdoRateSource($pdo, new CsvRateSource());
    $quoteEngine = new QuoteEngine(ratesConfig: $ratesConfig, rateSource: $rateSource);
    $rateRepo = new AdminRateRepository($pdo, $ratesConfig);
    $rateController = new RateController(
        rateRepository: $rateRepo,
        rateSource: $rateSource,
        viewRenderer: $viewRenderer,
        auditLogger: $auditLogger,
        ratesConfig: $ratesConfig,
        csvPath: 'public/data/prices.csv'
    );
    $emailRenderer = new ConfirmationEmailRenderer($publicSiteUrl);
    $cancellationEmailRenderer = new CancellationEmailRenderer($publicSiteUrl);
    $emailSender = new PhpMailSender();
    $mpAccessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] ?? getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
    $refundClient = new MercadoPagoRefundClient($mpAccessToken);

    $ledger = ReservationLedger::createDefault($pdo);

    $reservationController = new ReservationController(
        repository: $reservationRepo,
        viewRenderer: $viewRenderer,
        auditLogger: $auditLogger,
        ledger: $ledger,
        quoteEngine: $quoteEngine,
        emailRenderer: $emailRenderer,
        emailSender: $emailSender,
        publicSiteUrl: $publicSiteUrl,
        refundClient: $refundClient,
        cancellationEmailRenderer: $cancellationEmailRenderer
    );

    $calendarBlockRepo = new AdminCalendarBlockRepository($pdo);
    $calendarBlockController = new CalendarBlockController(
        blockRepository: $calendarBlockRepo,
        ledger: $ledger,
        viewRenderer: $viewRenderer,
        auditLogger: $auditLogger
    );

    // 4. Assemble Security Middlewares & Router
    $sessionMiddleware = new SessionMiddleware();
    $csrfMiddleware = new CsrfMiddleware();
    $authMiddleware = new AuthMiddleware();

    $router = new Router(
        sessionMiddleware: $sessionMiddleware,
        csrfMiddleware: $csrfMiddleware,
        authMiddleware: $authMiddleware
    );

    // 5. Register Declarative Administrative Routes
    $router->get('/login', [$authController, 'showLogin'])
        ->post('/login', [$authController, 'login'])
        ->get('/logout', [$authController, 'logout'])
        ->get('/', [$dashboardController, 'index'])
        ->get('/reservations', [$reservationController, 'list'])
        ->get('/reservations/new', [$reservationController, 'newReservation'])
        ->post('/reservations/quote-preview', [$reservationController, 'quotePreview'])
        ->post('/reservations/create-manual', [$reservationController, 'createManual'])
        ->get('/reservations/{uid}', [$reservationController, 'show'])
        ->get('/reservations/{uid}/registry', [$reservationController, 'showRegistry'])
        ->post('/reservations/{uid}/registry/complete', [$reservationController, 'completeRegistry'])
        ->post('/reservations/{uid}/door-code/override', [$reservationController, 'overrideDoorCode'])
        ->post('/reservations/{uid}/door-code/regenerate', [$reservationController, 'regenerateDoorCode'])
        ->get('/reservations/{uid}/cancel-modal', [$reservationController, 'cancelModal'])
        ->post('/reservations/{uid}/cancel', [$reservationController, 'cancel'])
        ->get('/rates', [$rateController, 'index'])
        ->get('/rates/new', [$rateController, 'newTier'])
        ->post('/rates', [$rateController, 'create'])
        ->get('/rates/{id}/edit', [$rateController, 'edit'])
        ->post('/rates/{id}', [$rateController, 'update'])
        ->delete('/rates/{id}', [$rateController, 'delete'])
        ->post('/rates/seed-from-csv', [$rateController, 'seedFromCsv'])
        ->get('/calendar-blocks', [$calendarBlockController, 'index'])
        ->get('/calendar-blocks/new', [$calendarBlockController, 'newHold'])
        ->post('/calendar-blocks', [$calendarBlockController, 'create'])
        ->delete('/calendar-blocks/{id}', [$calendarBlockController, 'delete'])
        ->post('/calendar-blocks/{id}/delete', [$calendarBlockController, 'delete']);

    // 6. Capture Request & Dispatch
    $request = Request::fromGlobals();
    $response = $router->dispatch($request, $_SESSION);
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
