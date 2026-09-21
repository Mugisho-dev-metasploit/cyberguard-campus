<?php

declare(strict_types=1);

/**
 * Helper of SensitiveLoginTraceTest (APP-07.4.3): one sign-in through the real LoginController
 * and AuthenticationService whose user lookup fails (TkThrowingPdo), left uncaught so that PHP
 * logs the exception with its stack trace, as it would for any request. Reads
 * {"identifier": …, "password": …} as JSON on stdin; never prints them. No throttle, no write.
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationService;

require __DIR__ . '/bootstrap.php';

$input = json_decode((string) stream_get_contents(STDIN), true);

$pdo = tk_connect(TkThrowingPdo::class);
$pdo->failOn = 'OR email = :email';   // the lookup inside AuthenticationService::authenticate()

$controller = new LoginController(new AuthenticationService(new UserRepository($pdo)), new SessionManager());

ob_start();
$controller->login(new HttpRequest('POST', '/login', is_array($input) ? $input : [], [], true, '198.51.100.250'));
