<?php

declare(strict_types=1);

use CyberGuard\Campus\Bootstrap\Environment;
use CyberGuard\Campus\Controllers\IncidentController;
use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Database\Database;
use CyberGuard\Campus\Middleware\AuthenticationMiddleware;
use CyberGuard\Campus\Middleware\AuthorizationMiddleware;
use CyberGuard\Campus\Repositories\IncidentRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Routing\Router;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\IncidentService;

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

$router->dispatch($request);
