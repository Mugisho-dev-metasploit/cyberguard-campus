<?php

declare(strict_types=1);

use CyberGuard\Campus\Bootstrap\Environment;
use CyberGuard\Campus\Controllers\AlertController;
use CyberGuard\Campus\Controllers\DeviceController;
use CyberGuard\Campus\Controllers\EventController;
use CyberGuard\Campus\Controllers\IncidentController;
use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Controllers\MetricsController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Database\Database;
use CyberGuard\Campus\Middleware\AuthenticationMiddleware;
use CyberGuard\Campus\Middleware\AuthorizationMiddleware;
use CyberGuard\Campus\Repositories\AlertRepository;
use CyberGuard\Campus\Repositories\DeviceRepository;
use CyberGuard\Campus\Repositories\EventRepository;
use CyberGuard\Campus\Repositories\IncidentRepository;
use CyberGuard\Campus\Repositories\MetricsRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Routing\Router;
use CyberGuard\Campus\Services\AlertService;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\DeviceService;
use CyberGuard\Campus\Services\EventService;
use CyberGuard\Campus\Services\IncidentService;
use CyberGuard\Campus\Services\MetricsService;

require dirname(__DIR__) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__, 2));

$request = HttpRequest::fromGlobals();

$connection = Database::connection();

$userRepository = new UserRepository(
    $connection
);

$authenticationService = new AuthenticationService(
    $userRepository
);

$sessionManager = new SessionManager();

$loginController = new LoginController(
    authenticationService: $authenticationService,
    sessionManager: $sessionManager,
);

$incidentRepository = new IncidentRepository(
    $connection
);

$incidentService = new IncidentService(
    $incidentRepository
);

$incidentController = new IncidentController(
    $incidentService
);

$eventRepository = new EventRepository(
    $connection
);

$eventService = new EventService(
    $eventRepository
);

$eventController = new EventController(
    $eventService
);

$alertRepository = new AlertRepository(
    $connection
);

$alertService = new AlertService(
    $alertRepository
);

$alertController = new AlertController(
    $alertService
);

$deviceRepository = new DeviceRepository(
    $connection
);

$deviceService = new DeviceService(
    $deviceRepository
);

$deviceController = new DeviceController(
    $deviceService
);

$metricsRepository = new MetricsRepository(
    $connection
);

$metricsService = new MetricsService(
    $metricsRepository
);

$metricsController = new MetricsController(
    $metricsService
);

$authenticationMiddleware = new AuthenticationMiddleware(
    $sessionManager
);

$authorizationMiddleware = new AuthorizationMiddleware(
    $sessionManager
);

$router = new Router();

$router->get(
    '/health',
    static function (): void {
        HttpResponse::json([
            'success' => true,
            'application' => 'CYBERGUARD CAMPUS',
            'status' => 'healthy',
        ]);
    }
);

$router->post(
    '/login',
    [$loginController, 'login']
);

$router->get(
    '/api/incidents',
    [$incidentController, 'index'],
    [
        [$authenticationMiddleware, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorizationMiddleware): void {
            $authorizationMiddleware->handle(
                $request,
                ['viewer', 'analyst', 'admin'],
                $next,
            );
        },
    ]
);

$router->patch(
    '/api/incidents/{id}',
    [$incidentController, 'update'],
    [
        [$authenticationMiddleware, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorizationMiddleware): void {
            $authorizationMiddleware->handle(
                $request,
                ['analyst', 'admin'],
                $next,
            );
        },
    ]
);

$router->get(
    '/api/events',
    [$eventController, 'index'],
    [
        [$authenticationMiddleware, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorizationMiddleware): void {
            $authorizationMiddleware->handle(
                $request,
                ['viewer', 'analyst', 'admin'],
                $next,
            );
        },
    ]
);

$router->get(
    '/api/alerts',
    [$alertController, 'index'],
    [
        [$authenticationMiddleware, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorizationMiddleware): void {
            $authorizationMiddleware->handle(
                $request,
                ['viewer', 'analyst', 'admin'],
                $next,
            );
        },
    ]
);

$router->get(
    '/api/devices',
    [$deviceController, 'index'],
    [
        [$authenticationMiddleware, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorizationMiddleware): void {
            $authorizationMiddleware->handle(
                $request,
                ['viewer', 'analyst', 'admin'],
                $next,
            );
        },
    ]
);

$router->get(
    '/api/metrics',
    [$metricsController, 'index'],
    [
        [$authenticationMiddleware, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorizationMiddleware): void {
            $authorizationMiddleware->handle(
                $request,
                ['viewer', 'analyst', 'admin'],
                $next,
            );
        },
    ]
);

$router->dispatch($request);
