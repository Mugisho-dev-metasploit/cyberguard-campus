<?php

declare(strict_types=1);

/**
 * APP-03.6 — absolute 6-hour session lifetime.
 *
 * Integration test of the real Router, LoginController, AuthenticationService,
 * AuthenticationMiddleware, AuthorizationMiddleware and SessionManager, with:
 *  - an injected clock (no waiting 6 hours),
 *  - a throwaway in-memory SQLite `users` table (the MariaDB database is never touched),
 *  - a random password generated for each run (no stored credentials),
 *  - a temporary session store, deleted at the end.
 * Each "request" closes the previous session and presents the session ID as the browser
 * would: through the session cookie only.
 *
 * Run with a PHP that has pdo_sqlite (the XAMPP runtime does):
 *   /opt/lampp/bin/php backend/tests/SessionExpirationTest.php   (exit code 0 = all passed)
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Middleware\AuthenticationMiddleware;
use CyberGuard\Campus\Middleware\AuthorizationMiddleware;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Routing\Router;
use CyberGuard\Campus\Services\AuthenticationService;

require dirname(__DIR__) . '/vendor/autoload.php';

// CLI: keep everything buffered so session cookies/headers can still be emitted.
ob_start();

const SESSION_COOKIE = 'cg_test_session';
const LIFETIME = 21600;

$_ENV['APP_ENV'] = 'testing';
$_ENV['SESSION_NAME'] = SESSION_COOKIE;

$sessionStore = sys_get_temp_dir() . '/cg-session-test-' . bin2hex(random_bytes(6));
mkdir($sessionStore, 0700);
ini_set('session.save_path', $sessionStore);
ini_set('session.gc_probability', '0');

/* Fixtures -------------------------------------------------------------------------- */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid TEXT NOT NULL, username TEXT NOT NULL, email TEXT NOT NULL,
        password_hash TEXT NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL,
        role TEXT NOT NULL, status TEXT NOT NULL,
        last_login_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT NULL
    )
SQL);

$password = bin2hex(random_bytes(16));
$users = new UserRepository($pdo);
$users->create('00000000-0000-4000-8000-000000000001', 'session.viewer', 'viewer@session.test',
    password_hash($password, PASSWORD_DEFAULT), 'Session', 'Viewer', 'viewer');

$now = 1_800_000_000;
$clock = static function () use (&$now): int {
    return $now;
};

/** One HTTP request: fresh application objects, session ID presented by cookie only. */
function request(string $method, string $path, ?string $sessionCookie, array $body = [], array $extraCookies = []): array
{
    global $pdo, $clock;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $_SESSION = [];

    // Edit $_COOKIE in place: PHP's session module reads this same array (reassigning it breaks the link).
    foreach (array_keys($_COOKIE) as $name) {
        unset($_COOKIE[$name]);
    }

    foreach ($extraCookies as $name => $value) {
        $_COOKIE[$name] = $value;
    }

    if ($sessionCookie !== null) {
        $_COOKIE[SESSION_COOKIE] = $sessionCookie;
    }

    // What PHP reads from the session cookie. Strict mode still rejects unknown or destroyed IDs.
    // ('' = no cookie: PHP issues a new ID, as for a first visit.)
    session_id($sessionCookie ?? '');

    $sessionManager = new SessionManager($clock);
    $authenticationService = new AuthenticationService(new UserRepository($pdo));
    $authentication = new AuthenticationMiddleware($sessionManager, $authenticationService);
    $authorization = new AuthorizationMiddleware($sessionManager);
    $login = new LoginController($authenticationService, $sessionManager);

    $protected = static fn (array $roles): array => [
        [$authentication, 'handle'],
        static function (HttpRequest $request, callable $next) use ($authorization, $roles): void {
            $authorization->handle($request, $roles, $next);
        },
    ];

    $router = new Router();
    $router->get('/health', static function (): void {
        HttpResponse::json(['success' => true, 'application' => 'CYBERGUARD CAMPUS', 'status' => 'healthy']);
    });
    $router->post('/login', [$login, 'login']);
    $router->get('/api/events', static function (): void {
        HttpResponse::json(['success' => true, 'message' => 'ok', 'data' => []]);
    }, $protected(['viewer', 'analyst', 'admin']));
    $router->patch('/api/incidents/{id}', static function (): void {
        HttpResponse::json(['success' => true, 'message' => 'ok']);
    }, $protected(['analyst', 'admin']));

    http_response_code(200);
    ob_start();
    $router->dispatch(new HttpRequest($method, $path, $body));
    $raw = (string) ob_get_clean();

    return [
        'status' => http_response_code(),
        'raw' => $raw,
        'json' => json_decode($raw, true),
        'session_id' => session_status() === PHP_SESSION_ACTIVE ? session_id() : null,
        'started' => session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['__session_started'] ?? null) : null,
    ];
}

function signIn(?string $cookie = null, ?string $pass = null): array
{
    global $password;

    return request('POST', '/login', $cookie, ['identifier' => 'session.viewer', 'password' => $pass ?? $password]);
}

/** Reads __session_started straight from the server-side session store. */
function storedStart(string $sessionId): ?int
{
    global $sessionStore;

    // The request's session is written to the store when it closes.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // The session handler deletes files outside PHP's stat cache.
    clearstatcache();

    $file = "$sessionStore/sess_$sessionId";

    if (!is_file($file)) {
        return null;
    }

    return preg_match('/__session_started\|i:(\d+);/', (string) file_get_contents($file), $m) === 1 ? (int) $m[1] : null;
}

function sessionExists(string $sessionId): bool
{
    global $sessionStore;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // The session handler deletes files outside PHP's stat cache.
    clearstatcache();

    return is_file("$sessionStore/sess_$sessionId");
}

$results = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok, $detail];
}

const EXPIRED_BODY = ['success' => false, 'message' => 'Authentication required.'];

/* 0. Contract ------------------------------------------------------------------------ */

check('lifetime constant is 21600 seconds', SessionManager::SESSION_LIFETIME_SECONDS === 21600);

$anonymous = request('GET', '/api/events', null);
check('no session → 401 (existing contract)', $anonymous['status'] === 401 && $anonymous['json'] === EXPIRED_BODY, $anonymous['raw']);
check('anonymous request creates no session, so never writes a start time (APP-07.3.1)', $anonymous['session_id'] === null
    && glob("$sessionStore/sess_*") === []);

/* 1. New session --------------------------------------------------------------------- */

$now = $t0 = 1_800_000_000;
$login = signIn();
$sid = $login['session_id'];
check('T1 login → 200, session ID issued', $login['status'] === 200 && $login['json']['success'] === true && is_string($sid) && $sid !== '');
check('T1 __session_started = T0 in the session store', storedStart($sid) === $t0, (string) storedStart($sid));
check('T1 login response has no session / lifetime data', !str_contains($login['raw'], (string) $t0) && !str_contains($login['raw'], '21600')
    && !str_contains($login['raw'], 'password') && !str_contains($login['raw'], $sid));

/* 2. Timestamp kept across requests --------------------------------------------------- */

$starts = [];
foreach ([1, 60, 3600] as $offset) {
    $now = $t0 + $offset;
    $r = request('GET', '/api/events', $sid);
    $starts[] = [$r['status'], $r['started'], storedStart($sid), $r['session_id'] === $sid];
}
check('T2 requests 1/2/3 → 200, same session ID', array_column($starts, 0) === [200, 200, 200] && !in_array(false, array_column($starts, 3), true));
check('T2 __session_started identical on every request (never rewritten)',
    array_column($starts, 1) === [$t0, $t0, $t0] && array_column($starts, 2) === [$t0, $t0, $t0], json_encode($starts));

/* 3–4. Boundary ------------------------------------------------------------------------ */

$now = $t0 + LIFETIME - 1;
$r = request('GET', '/api/events', $sid);
check('T3 T0 + 5h59m59s → 200 (still valid)', $r['status'] === 200);

$now = $t0 + LIFETIME;
$r = request('GET', '/api/events', $sid);
check('T4 T0 + 6h exactly → 401', $r['status'] === 401, (string) $r['status']);
check('T4 401 uses the existing JSON body, nothing about the lifetime', $r['json'] === EXPIRED_BODY
    && str_starts_with($r['raw'], '{') && !str_contains($r['raw'], 'expir'), $r['raw']);
check('T4 session deleted from the server store', !sessionExists($sid));
check('T4 session closed for the rest of the request', $r['session_id'] === null);

/* 5. After expiration --------------------------------------------------------------------- */

$now = $t5 = $t0 + 10 * 3600;
$sid5 = signIn()['session_id'];
$now = $t5 + LIFETIME + 1;
$r = request('GET', '/api/events', $sid5);
check('T5 T0 + 6h + 1s → 401', $r['status'] === 401 && $r['json'] === EXPIRED_BODY);

/* 6. Destroyed session cannot be reused ------------------------------------------------ */

$now = $t0 + LIFETIME + 5;
$r = request('GET', '/api/events', $sid);
check('T6 same session ID again → 401', $r['status'] === 401);
check('T6 old ID is not revived (strict mode issues a different, empty session)', $r['session_id'] === null || $r['session_id'] !== $sid);
$now = $t0 + 100; // even with a clock set back inside the old window
$r = request('GET', '/api/events', $sid);
check('T6 destroyed session stays rejected even inside the old 6h window', $r['status'] === 401);

/* 7. Activity does not extend the session ---------------------------------------------- */

$now = $t7 = $t0 + 20 * 3600;
$sid7 = signIn()['session_id'];
$activity = [];
foreach ([3600, 3 * 3600, 5 * 3600, 5 * 3600 + 59 * 60] as $offset) {
    $now = $t7 + $offset;
    $r = request('GET', '/api/events', $sid7);
    $activity[] = $r['status'];
}
check('T7 activity at +1h, +3h, +5h, +5h59 → 200', $activity === [200, 200, 200, 200], json_encode($activity));
check('T7 start time unchanged after activity', storedStart($sid7) === $t7);
$now = $t7 + LIFETIME;
$r = request('GET', '/api/events', $sid7);
check('T7 T0 + 6h → 401 despite the activity', $r['status'] === 401);

/* 8. New login after expiration ---------------------------------------------------------- */

$now = $t8 = $t7 + LIFETIME + 120;
$login8 = signIn($sid7);
$sid8 = $login8['session_id'];
check('T8 login after expiration → 200 with a new session ID', $login8['status'] === 200 && $sid8 !== $sid7);
check('T8 new start time T1 = login time', storedStart($sid8) === $t8);
$now = $t8 + LIFETIME - 1;
check('T8 valid until T1 + 6h - 1s', request('GET', '/api/events', $sid8)['status'] === 200);
$now = $t8 + LIFETIME;
check('T8 expires at T1 + 6h', request('GET', '/api/events', $sid8)['status'] === 401);

/* 9. Session fixation ------------------------------------------------------------------- */

$now = $t9 = $t8 + LIFETIME + 60;
$planted = request('GET', '/health', null); // /health does not open a session
check('T9 /health opens no session', $planted['session_id'] === null);
// An existing, unauthenticated session whose ID an attacker knows (the API no longer opens
// anonymous sessions since APP-07.3.1, so it is planted in the store).
$preLogin = 'tk' . bin2hex(random_bytes(16));
file_put_contents("$sessionStore/sess_$preLogin", 'note|s:9:"pre-login";');
$login9 = signIn($preLogin);
$sid9 = $login9['session_id'];
check('T9 login issues a new ID (pre-login ID not kept)', is_string($preLogin) && $sid9 !== $preLogin);
check('T9 pre-login session deleted server-side', !sessionExists($preLogin));
check('T9 pre-login ID is not authenticated', request('GET', '/api/events', $preLogin)['status'] === 401);
$attacker = signIn('attacker-chosen-session-id');
check('T9 attacker-chosen ID is never adopted', $attacker['status'] === 200 && $attacker['session_id'] !== 'attacker-chosen-session-id');
$reLogin = signIn($sid9);
check('T9 signing in again destroys the previous authenticated session', $reLogin['session_id'] !== $sid9 && !sessionExists($sid9)
    && request('GET', '/api/events', $sid9)['status'] === 401);

/* 10. Public routes with an expired session ------------------------------------------- */

$now = $t10 = $t9 + 100;
$sid10 = signIn()['session_id'];
$now = $t10 + LIFETIME + 30;
$health = request('GET', '/health', $sid10);
check('T10 GET /health with an expired session → 200', $health['status'] === 200 && ($health['json']['status'] ?? null) === 'healthy');
$bad = signIn($sid10, 'wrong-' . bin2hex(random_bytes(4)));
check('T10 POST /login reachable with an expired session (wrong password → 401 Invalid credentials)',
    $bad['status'] === 401 && $bad['json'] === ['success' => false, 'message' => 'Invalid credentials.']);
$good = signIn($sid10);
check('T10 POST /login with an expired session → 200, new session', $good['status'] === 200 && $good['session_id'] !== $sid10
    && request('GET', '/api/events', $good['session_id'])['status'] === 200);

/* Client cannot influence the lifetime --------------------------------------------------- */

$now = $t11 = $t10 + 2 * LIFETIME;
$sid11 = signIn()['session_id'];
$now = $t11 + LIFETIME;
$spoof = request('GET', '/api/events', $sid11, ['__session_started' => $now, 'expires_at' => $now + LIFETIME],
    ['__session_started' => (string) $now, 'session_lifetime' => '999999']);
check('client-sent values (body, cookies) cannot extend the session', $spoof['status'] === 401);

/* Tampered / incomplete server data fails closed ---------------------------------------- */

$now = $t12 = $t11 + 2 * LIFETIME;
$sid12 = signIn()['session_id'];
sessionExists($sid12); // closes the login request, so its data is on disk before editing it
$file = "$sessionStore/sess_$sid12";
file_put_contents($file, preg_replace('/__session_started\|i:\d+;/', '__session_started|s:10:"' . $t12 . '";', (string) file_get_contents($file)));
check('non-integer start time → 401 and destroyed', request('GET', '/api/events', $sid12)['status'] === 401 && !sessionExists($sid12));

/* RBAC unchanged: 403 only for a valid session without the role ---------------------------- */

$now = $t13 = $t12 + 2 * LIFETIME;
$sid13 = signIn()['session_id'];
$forbidden = request('PATCH', '/api/incidents/1', $sid13);
check('valid viewer on analyst route → 403 (unchanged)', $forbidden['status'] === 403 && $forbidden['json'] === ['success' => false, 'message' => 'Insufficient permissions.']);
$now = $t13 + LIFETIME;
$expiredForbidden = request('PATCH', '/api/incidents/1', $sid13);
check('expired viewer on analyst route → 401, never 403', $expiredForbidden['status'] === 401 && $expiredForbidden['json'] === EXPIRED_BODY);

/* Report --------------------------------------------------------------------------------- */

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

array_map('unlink', glob("$sessionStore/sess_*") ?: []);
rmdir($sessionStore);
ob_end_clean();

$failed = 0;
foreach ($results as [$name, $ok, $detail]) {
    $failed += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . (!$ok && $detail !== '' ? " — $detail" : '') . PHP_EOL;
}

$total = count($results);
echo PHP_EOL . ($total - $failed) . "/$total checks passed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
