<?php

declare(strict_types=1);

/**
 * APP074-04 — in production the session cookie is Secure, whatever the source of APP_ENV:
 * .env ($_ENV), the web server (SetEnv → $_SERVER) or the process environment (PHP-FPM,
 * containers). In development (the laboratory, plain HTTP) it is not, so sign-in still works.
 *
 * Over HTTP: private PHP servers with and without APP_ENV=production in their environment (the
 * .env file is left as it is). In process: $_SERVER['APP_ENV'] alone. Temporary user with a
 * random password; everything created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/ProductionCookieTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Core\SessionManager;

require __DIR__ . '/support/bootstrap.php';

ob_start();
$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-prod-cookie-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
$servers = [];

/** The session Set-Cookie lines of a request (values not kept). */
function session_cookies(string $url, string $method, ?array $body = null, ?string $sid = null): array
{
    global $cookieName;
    $h = "Content-Type: application/json\r\n" . ($sid !== null ? "Cookie: $cookieName=$sid\r\n" : '');
    $c = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true]]);
    @file_get_contents($url, false, $c);
    $out = [];
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('/^Set-Cookie:\s*' . preg_quote($cookieName, '/') . '=([^;]*)(.*)$/i', $l, $m) === 1) {
            $out[] = ['value' => $m[1], 'attributes' => $m[2]];
        }
    }

    return $out;
}

function flags(array $cookie): array
{
    return [
        'secure' => preg_match('/;\s*secure(;|$)/i', $cookie['attributes']) === 1,
        'httponly' => preg_match('/;\s*httponly(;|$)/i', $cookie['attributes']) === 1,
        'lax' => preg_match('/;\s*samesite=lax(;|$)/i', $cookie['attributes']) === 1,
    ];
}

try {
    $password = bin2hex(random_bytes(16));
    $id = tk_user('viewer');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $id]);
    $user = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $id")->fetchColumn();

    foreach (['production (process environment)' => ['APP_ENV' => 'production'], 'development (.env only)' => []] as $label => $env) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $childEnv = getenv();
        unset($childEnv['APP_ENV']);
        $servers[] = $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', "session.save_path=$dir", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $childEnv + $env);
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(100000);
        }
        $base = "http://127.0.0.1:$port/index.php";
        $login = session_cookies("$base/login", 'POST', ['identifier' => $user, 'password' => $password]);
        $session = end($login) ?: ['value' => '', 'attributes' => ''];
        $logout = session_cookies("$base/logout", 'POST', null, $session['value']);
        $production = $env !== [];
        check("$label: sign-in cookie " . ($production ? 'is' : 'is not') . ' Secure, and is HttpOnly + SameSite=Lax', $login !== []
            && array_filter($login, static fn (array $c): bool => flags($c)['secure'] !== $production || !flags($c)['httponly'] || !flags($c)['lax']) === []);
        check("$label: the sign-out deletion cookie keeps the same attributes", $logout !== [] && end($logout)['value'] === 'deleted'
            && flags(end($logout)) === ['secure' => $production, 'httponly' => true, 'lax' => true]);
    }

    // Web server variable only (Apache SetEnv → $_SERVER), .env left as it is.
    $_SERVER['APP_ENV'] = 'production';
    ini_set('session.save_path', $dir);
    (new SessionManager())->start();
    check('APP_ENV from the web server ($_SERVER) alone: cookie parameters are Secure', session_get_cookie_params()['secure'] === true);
    session_destroy();
    unset($_SERVER['APP_ENV']);
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
