<?php

declare(strict_types=1);

/**
 * APP-07.4.5 — a sign-in is reported only when its session is really established.
 *
 * In process: the real LoginController, AuthenticationService, LoginThrottle and SessionManager,
 * with a test session handler wrapping PHP's own "files" handler (private store). Switches in the
 * handler make session_start() fail (read refused), session_regenerate_id(true) fail (destroy of
 * the old session refused) or throw — deterministically, without any global configuration.
 * Over HTTP: a private PHP server whose session store cannot be used, and the running Apache
 * with a session cookie it cannot read (a file it does not own): no false success.
 * Temporary user with a random password for this run; everything created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/SessionEstablishmentTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\LoginThrottle;

require __DIR__ . '/support/bootstrap.php';

// Keep output buffered so the controller can still send headers; PHP's own session warnings go
// to a private log (checked below), never to the shared php_error_log.
ob_start();
$dir = sys_get_temp_dir() . '/cg-session-establish-' . bin2hex(random_bytes(6));
mkdir("$dir/store", 0700, true);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', "$dir/php-error.log");

const COOKIE = 'cg_establish_test';
const UNABLE = ['success' => false, 'message' => 'Unable to sign in.'];
const INVALID = ['success' => false, 'message' => 'Invalid credentials.'];
const SOURCE = '198.51.100.77';

$apacheCookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';   // the application's cookie, for the Apache checks
$_ENV['SESSION_NAME'] = COOKIE;
$before = tk_counts();

/** PHP's files handler with switches to make session operations fail on demand. */
final class TkFlakySessionHandler extends SessionHandler implements SessionUpdateTimestampHandlerInterface
{
    public bool $failRead = false;
    public bool $failDestroy = false;
    public bool $throwOnRead = false;

    public function read(string $id): string|false
    {
        if ($this->throwOnRead) {
            throw new RuntimeException('Simulated session store failure (test double)');
        }

        return $this->failRead ? false : parent::read($id);
    }

    public function destroy(string $id): bool
    {
        return $this->failDestroy ? false : parent::destroy($id);
    }

    public function validateId(string $id): bool
    {
        return is_file(session_save_path() . "/sess_$id");
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return parent::write($id, $data);
    }
}

session_save_path("$dir/store");
$handler = new TkFlakySessionHandler();
session_set_save_handler($handler, true);

/** One sign-in request: fresh objects, session presented by cookie only (as a browser would). */
function sign_in(array $body, ?string $cookie = null): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $_SESSION = [];
    foreach (array_keys($_COOKIE) as $name) {
        unset($_COOKIE[$name]);
    }
    if ($cookie !== null) {
        $_COOKIE[COOKIE] = $cookie;
    }
    session_id($cookie ?? '');

    $controller = new LoginController(new AuthenticationService(new UserRepository(tk_pdo())), new SessionManager(),
        new LoginThrottle(new LoginThrottleRepository(tk_pdo())));
    http_response_code(200);
    ob_start();
    $controller->login(new HttpRequest('POST', '/login', $body, [], true, SOURCE));
    $raw = (string) ob_get_clean();
    $active = session_status() === PHP_SESSION_ACTIVE;

    return ['status' => http_response_code(), 'raw' => $raw, 'json' => json_decode($raw, true), 'active' => $active,
        'sid' => $active ? session_id() : null, 'session' => $_SESSION ?? []];
}

/** Stored sessions (after closing the current one) that carry an authenticated identity. */
function authenticated_files(string $store): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    clearstatcache();

    return array_values(array_filter(glob("$store/sess_*") ?: [],
        static fn (string $f): bool => str_contains((string) file_get_contents($f), 'authenticated|b:1')));
}

function buckets(string $identifier): int
{
    $keys = (new LoginThrottleRepository(tk_pdo()))->keys($identifier, SOURCE);
    $s = tk_pdo()->prepare('SELECT COUNT(*) FROM login_throttle WHERE throttle_key IN (?, ?)');
    $s->execute(array_values($keys));

    return (int) $s->fetchColumn();
}

$server = null;

try {
    $password = bin2hex(random_bytes(16));
    $id = tk_user('analyst');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $id]);
    $username = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $id")->fetchColumn();
    $good = ['identifier' => $username, 'password' => $password];
    $store = "$dir/store";

    /* T6 — invalid credentials: unchanged ---------------------------------------------------------- */
    $bad = sign_in(['identifier' => $username, 'password' => 'wrong-' . bin2hex(random_bytes(6))]);
    check('T6 invalid credentials → 401 "Invalid credentials.", no session started', $bad['status'] === 401 && $bad['json'] === INVALID
        && !$bad['active'] && glob("$store/sess_*") === []);

    /* T2 — session_start() fails -------------------------------------------------------------------- */
    $handler->failRead = true;
    $t2 = sign_in($good);
    $handler->failRead = false;
    check('T2 session_start() failure → 500 "Unable to sign in.", not 200', $t2['status'] === 500 && $t2['json'] === UNABLE, $t2['status'] . ' ' . $t2['raw']);
    check('T2 no session active, no identity in $_SESSION, nothing authenticated stored', !$t2['active'] && $t2['session'] === []
        && authenticated_files($store) === []);
    check('T5 throttle not cleared by a failed session (the attempt stays counted)', buckets($username) === 2);

    /* T3 — session_regenerate_id(true) fails (presented pre-login session) ---------------------------- */
    $preLogin = 'tk' . bin2hex(random_bytes(16));
    file_put_contents("$store/sess_$preLogin", 'note|s:9:"pre-login";');
    $handler->failDestroy = true;
    $t3 = sign_in($good, $preLogin);
    $handler->failDestroy = false;
    check('T3 session_regenerate_id() failure → 500 "Unable to sign in.", not 200', $t3['status'] === 500 && $t3['json'] === UNABLE, $t3['status'] . ' ' . $t3['raw']);
    check('T3 / T7 partial state cleaned: no active session, $_SESSION empty', !$t3['active'] && $t3['session'] === []);
    clearstatcache();
    check('T7 the pre-login session was never upgraded: its stored data carries no identity', is_file("$store/sess_$preLogin")
        && !str_contains((string) file_get_contents("$store/sess_$preLogin"), 'user_id') && authenticated_files($store) === []);
    $sm = new SessionManager();
    $_COOKIE = [COOKIE => $preLogin];
    session_id($preLogin);
    check('T7 the pre-login session ID is not authenticated afterwards', $sm->isAuthenticated() === false);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    check('T5 throttle still not cleared after the second failure', buckets($username) === 2);

    /* T4 — an exception while establishing the session is never a success ------------------------------ */
    $handler->throwOnRead = true;
    $t4 = sign_in($good);
    $handler->throwOnRead = false;
    check('T4 exception in the session store → 500 generic, no detail (no class, message or path)', $t4['status'] === 500 && $t4['json'] === UNABLE
        && !preg_match('/Simulated|Exception|RuntimeException|\/tmp|\/opt|store/i', $t4['raw']));
    check('T4 no failure mode produced "success": true', ($t2['json']['success'] ?? null) === false && ($t3['json']['success'] ?? null) === false
        && ($t4['json']['success'] ?? null) === false && !isset($t2['json']['user'], $t3['json']['user'], $t4['json']['user']));
    check('T4 / T7 nothing authenticated stored after the exception', !$t4['active'] && $t4['session'] === [] && authenticated_files($store) === []);

    /* T1 — normal session: 200, identity and T0, new ID; throttle cleared only now ----------------------- */
    $t1 = sign_in($good, $preLogin);
    check('T1 valid credentials, working session → 200 success', $t1['status'] === 200 && ($t1['json']['success'] ?? null) === true);
    check('T1 session active under a new ID with identity and __session_started', $t1['active'] && $t1['sid'] !== $preLogin
        && ($t1['session']['user_id'] ?? null) === $id && ($t1['session']['authenticated'] ?? null) === true && is_int($t1['session']['__session_started'] ?? null));
    check('T1 the new session is stored and usable (isAuthenticated on the next request)', authenticated_files($store) === ["$store/sess_{$t1['sid']}"]
        && (static function () use ($t1): bool {
            $_COOKIE = [COOKIE => $t1['sid']];
            session_id($t1['sid']);
            $ok = (new SessionManager())->isAuthenticated();
            session_write_close();

            return $ok;
        })());
    check('T5 throttle cleared only after the successful, established sign-in', buckets($username) === 0);

    /* Security: no secret in responses or in the logged warnings -------------------------------------- */
    $responses = $t1['raw'] . $t2['raw'] . $t3['raw'] . $t4['raw'] . $bad['raw'];
    clearstatcache();
    $log = (string) @file_get_contents("$dir/php-error.log");
    $hash = (string) tk_pdo()->query("SELECT password_hash FROM users WHERE id = $id")->fetchColumn();
    $reference = (new ReflectionClassConstant(AuthenticationService::class, 'REFERENCE_HASH'))->getValue();
    check('no password, bcrypt hash, REFERENCE_HASH or session ID in any response', !str_contains($responses, $password)
        && !preg_match('/\$2[aby]\$/', $responses) && !str_contains($responses, $t1['sid']) && !str_contains($responses, $preLogin));
    check('no password, hash, REFERENCE_HASH or session ID in the logged session warnings', !str_contains($log, $password) && !str_contains($log, $hash)
        && !str_contains($log, $reference) && !str_contains($log, $t1['sid']) && !str_contains($log, $preLogin), strlen($log) . ' bytes');

    /* HTTP — private PHP server whose session store cannot be used --------------------------------------- */
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$dir/server.log",
        '-d', "session.save_path=$dir/missing-directory", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    $post = static function (string $url, array $body, ?string $cookie = null): array {
        $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n" . ($cookie ? "Cookie: $cookie\r\n" : ''),
            'content' => json_encode($body), 'ignore_errors' => true, 'timeout' => 30]]);
        $raw = (string) @file_get_contents($url, false, $c);
        // The browser keeps the last Set-Cookie: a session ID followed by its deletion leaves nothing.
        $issued = null;
        foreach ($http_response_header ?? [] as $l) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $l, $m) === 1) {
                $issued = $m[2] === 'deleted' || $m[2] === '' ? null : $m[2];
            }
        }

        return [(int) (explode(' ', $http_response_header[0] ?? '0 0')[1] ?? 0), json_decode($raw, true), $issued];
    };
    [$s, $json, $issued] = $post("http://127.0.0.1:$port/index.php/login", $good);
    check('HTTP private server, unusable session store: valid credentials → 500 generic, session cookie left deleted', $s === 500 && $json === UNABLE && $issued === null,
        "$s " . json_encode($json));
    proc_terminate($server);
    proc_close($server);
    $server = null;

    /* HTTP — running Apache with a session cookie it cannot read (file not owned by Apache) --------------- */
    $base = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/') . '/backend/public/index.php';
    $name = $apacheCookieName;
    $foreign = 'tk' . bin2hex(random_bytes(16));
    file_put_contents("/opt/lampp/temp/sess_$foreign", '');
    chmod("/opt/lampp/temp/sess_$foreign", 0666);
    [$s, $json, $issued] = $post("$base/login", $good, "$name=$foreign");
    @unlink("/opt/lampp/temp/sess_$foreign");
    check('HTTP Apache, unreadable session: valid credentials → 500 generic (was 200 success without a session)', $s === 500 && $json === UNABLE && $issued === null,
        "$s " . json_encode($json));
    [$s, $json, $issued] = $post("$base/login", $good);
    check('HTTP Apache, same credentials without that cookie → 200 success with a session cookie', $s === 200 && ($json['success'] ?? null) === true && $issued !== null);
    [$s2] = $post("$base/login", ['identifier' => $username, 'password' => 'wrong-' . bin2hex(random_bytes(6))]);
    check('HTTP Apache, invalid credentials → 401 (unchanged)', $s2 === 401);
    if ($issued !== null) {
        $c = stream_context_create(['http' => ['method' => 'POST', 'header' => "Cookie: $name=$issued\r\n", 'ignore_errors' => true]]);
        @file_get_contents("$base/logout", false, $c);   // end the session opened on Apache
    }
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    foreach (["$dir/store/*", "$dir/*"] as $pattern) {
        foreach (glob($pattern) ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
    }
    @rmdir("$dir/store");
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
