<?php

declare(strict_types=1);

/**
 * Helper of SensitiveLoginTraceTest (APP-07.4.3, APP074-18): one sign-in through the real
 * LoginController and AuthenticationService, left uncaught so that PHP logs the exception with its
 * stack trace, as it would for any request. The failure happens in the user lookup (default) or,
 * with argument "throttle", in the throttle's key computation (TkThrowingPdo). Reads
 * {"identifier": …, "password": …} as JSON on stdin; never prints them. No database write.
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\LoginThrottle;

require __DIR__ . '/bootstrap.php';

$input = json_decode((string) stream_get_contents(STDIN), true);

$pdo = tk_connect(TkThrowingPdo::class);
$throttle = null;

if (($argv[1] ?? '') === 'throttle') {
    $pdo->failOn = 'WEIGHT_STRING';          // the throttle keys, computed from the identifier
    $throttle = new LoginThrottle(new LoginThrottleRepository($pdo));
} else {
    $pdo->failOn = 'OR email = :email';      // the lookup inside AuthenticationService::authenticate()
}

$controller = new LoginController(new AuthenticationService(new UserRepository($pdo)), new SessionManager(), $throttle);

ob_start();
$controller->login(new HttpRequest('POST', '/login', is_array($input) ? $input : [], [], true, '198.51.100.250'));
