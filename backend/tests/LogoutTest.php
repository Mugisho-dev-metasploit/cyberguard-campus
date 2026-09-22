<?php

declare(strict_types=1);

/**
 * APP-07.3.2 — POST /logout: server-side session invalidation.
 *
 * Real front controller (backend/public/index.php) over HTTP, on a private PHP server and a
 * private session store. Sessions are written to that store for temporary test users; the
 * one real sign-in uses a random password generated for this run and stored only as a hash.
 * The users carry the per-run marker and are deleted at the end.
 *
 * Run: /opt/lampp/bin/php backend/tests/LogoutTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$store = sys_get_temp_dir() . '/cg-logout-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);
$server = null;
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';

const LOGGED_OUT = ['success' => true, 'message' => 'Logged out successfully.'];
const AUTH_REQUIRED = ['success' => false, 'message' => 'Authentication required.'];

function plant_session(string $store, int $userId, string $role, ?int $startedAt = null): string
{
    $sid = 'tk' . bin2hex(random_bytes(16));
    $data = ['user_id' => $userId, 'user_uuid' => tk_uuid(), 'role' => $role, 'authenticated' => true, '__session_started' => $startedAt ?? time() - 60];
    $encoded = '';
    foreach ($data as $key => $value) {
        $encoded .= $key . '|' . serialize($value);
    }
    file_put_contents("$store/sess_$sid", $encoded);

    return $sid;
}

function session_exists(string $store, string $sid): bool
{
    clearstatcache();

    return is_file("$store/sess_$sid");
}

/** @return list<string> */
function store_ids(string $store): array
{
    clearstatcache();

    return array_map(static fn (string $f): string => substr(basename($f), 5), glob("$store/sess_*") ?: []);
}

/**
 * @param array<string, string> $cookies extra cookies
 * @return array{status: int, raw: string, json: mixed, cookies: list<string>} cookies = Set-Cookie header values
 */
function http(string $method, string $path, ?string $sid = null, ?string $rawBody = null, array $cookies = []): array
{
    global $cookieName;

    if ($sid !== null) {
        $cookies = [$cookieName => $sid] + $cookies;
    }
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\n";
    if ($cookies !== []) {
        $headers .= 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($cookies), $cookies)) . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $rawBody ?? '', 'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $raw = (string) @file_get_contents($GLOBALS['base'] . $path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    $setCookies = [];
    foreach ($http_response_header ?? [] as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $setCookies[] = trim(substr($line, 11));
        }
    }

    return ['status' => (int) ($m[1] ?? 0), 'raw' => $raw, 'json' => json_decode($raw, true), 'cookies' => $setCookies];
}

function logout(?string $sid, ?string $rawBody = null, string $query = '', array $cookies = []): array
{
    return http('POST', '/logout' . $query, $sid, $rawBody, $cookies);
}

/** The last Set-Cookie for the session cookie (the one the browser keeps), or null. */
function final_session_cookie(array $response): ?string
{
    global $cookieName;
    $mine = array_values(array_filter($response['cookies'], static fn (string $c): bool => str_starts_with($c, "$cookieName=")));

    return $mine === [] ? null : end($mine);
}

/** The browser is told to drop the session cookie, with the attributes it was created with. */
function clears_cookie(array $response): bool
{
    $cookie = final_session_cookie($response);

    return $cookie !== null
        && preg_match('/expires=([^;]+)/i', $cookie, $m) === 1 && strtotime($m[1]) < time()
        && preg_match('/max-age=0(;|$)/i', $cookie) === 1
        && preg_match('/;\s*path=\/(;|$)/i', $cookie) === 1
        && stripos($cookie, 'httponly') !== false
        && preg_match('/samesite=lax/i', $cookie) === 1
        && stripos($cookie, 'secure') === false; // APP_ENV is not production in tests
}

/** Nothing identifying in the body: only the generic message, no session ID. */
function generic(array $response, string ...$secrets): bool
{
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($response['raw'], $secret)) {
            return false;
        }
    }

    return $response['status'] === 200 && $response['json'] === LOGGED_OUT;
}

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $GLOBALS['base'] = "http://127.0.0.1:$port/index.php";
    $server = proc_open([PHP_BINARY, '-d', "session.save_path=$store", '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    $analyst = tk_user('analyst');
    $other = tk_user('admin');

    /* 1–2. Authenticated logout, then the old ID is useless ----------------------------------- */
    $sid = plant_session($store, $analyst, 'analyst');
    check('setup: planted session is valid (GET /api/metrics → 200)', http('GET', '/api/metrics', $sid)['status'] === 200);
    $out = logout($sid);
    check('1. authenticated logout → 200, generic JSON, no session ID in the body', generic($out, $sid), $out['raw']);
    check('1. session cookie invalidated (expired, Max-Age=0, Path=/, HttpOnly, SameSite=Lax)', clears_cookie($out), (string) final_session_cookie($out));
    check('1. session deleted from the server store', !session_exists($store, $sid));
    $reuse = http('GET', '/api/metrics', $sid);
    $reusePatch = http('PATCH', '/api/incidents/1', $sid, '{"status":"acknowledged"}');
    check('2. old session ID after logout → 401 Authentication required. (GET /api/metrics)', $reuse['status'] === 401 && $reuse['json'] === AUTH_REQUIRED);
    check('2. old session ID after logout → 401 on PATCH too, and it is not recreated', $reusePatch['status'] === 401 && !session_exists($store, $sid));
    $again = logout($sid);
    check('logout is idempotent: second logout with the same ID → same 200 response', generic($again, $sid) && !session_exists($store, $sid));

    /* 3 + 6. No session: 200, nothing created -------------------------------------------------- */
    $ids = store_ids($store);
    $anon = logout(null);
    check('3. logout without cookie → 200, same generic JSON', generic($anon));
    check('6. logout without cookie: no Set-Cookie, no session stored', $anon['cookies'] === [] && store_ids($store) === $ids);

    /* 4. Unknown / corrupted session ID ------------------------------------------------------- */
    $unknown = logout('tk' . str_repeat('0', 32));
    check('4. unknown session ID → 200, same generic JSON, cookie invalidated', generic($unknown) && clears_cookie($unknown));
    check('4. unknown session ID: nothing left in the store', store_ids($store) === $ids);
    $corrupt = logout('bad!value$%'); // characters PHP refuses in a session ID
    check('4. malformed session ID → 200, same generic JSON, nothing stored', generic($corrupt) && store_ids($store) === $ids);
    $garbledSid = 'tk' . bin2hex(random_bytes(16));
    file_put_contents("$store/sess_$garbledSid", 'not a session payload');
    $garbled = logout($garbledSid);
    check('4. corrupted session data → 200, same generic JSON, session file deleted', generic($garbled) && !session_exists($store, $garbledSid));

    /* 5. Expired session ---------------------------------------------------------------------- */
    $expired = plant_session($store, $analyst, 'analyst', time() - 21600);
    $expiredOut = logout($expired);
    check('5. expired session → 200, same generic JSON (no "expired" hint), cookie invalidated', generic($expiredOut, $expired) && clears_cookie($expiredOut)
        && stripos($expiredOut['raw'], 'expir') === false);
    check('5. expired session deleted and unusable (401)', !session_exists($store, $expired) && http('GET', '/api/metrics', $expired)['status'] === 401);
    check('identical body for valid, absent, unknown, corrupted and expired sessions',
        count(array_unique([$out['raw'], $anon['raw'], $unknown['raw'], $corrupt['raw'], $garbled['raw'], $expiredOut['raw']])) === 1);

    /* 7. Sign in after logout ----------------------------------------------------------------- */
    $password = bin2hex(random_bytes(16)); // generated for this run, never stored in clear
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $analyst]);
    $username = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $analyst")->fetchColumn();
    $credentials = json_encode(['identifier' => $username, 'password' => $password]);
    $sessionFrom = static function (array $response) use ($cookieName): ?string {
        $cookie = final_session_cookie($response);

        return $cookie !== null && preg_match('/^' . preg_quote($cookieName, '/') . '=([^;]+)/', $cookie, $m) === 1 && $m[1] !== 'deleted' ? $m[1] : null;
    };
    $login1 = http('POST', '/login', null, $credentials);
    $first = $sessionFrom($login1);
    check('7. sign in → 200 with a session', $login1['status'] === 200 && $first !== null && http('GET', '/api/metrics', $first)['status'] === 200);
    $out7 = logout($first);
    check('7. logout of that session → 200, session deleted', generic($out7, (string) $first) && !session_exists($store, (string) $first));
    $login2 = http('POST', '/login', $first, $credentials); // the browser may still send the old cookie
    $second = $sessionFrom($login2);
    check('7. sign in again → 200 with a new, independent session ID', $login2['status'] === 200 && $second !== null && $second !== $first
        && http('GET', '/api/metrics', $second)['status'] === 200);
    check('7. the first session ID still does not work', http('GET', '/api/metrics', (string) $first)['status'] === 401 && !session_exists($store, (string) $first));
    logout($second);

    /* 8. Only POST ------------------------------------------------------------------------------ */
    $keep = plant_session($store, $analyst, 'analyst');
    $methods = [];
    foreach (['GET', 'PATCH', 'PUT', 'DELETE'] as $method) {
        $r = http($method, '/logout', $keep);
        $methods[$method] = [$r['status'], $r['json']['message'] ?? null];
    }
    // APP074-12: a known path with another method answers 405 (was 404 "Route not found.").
    $notAllowed = [405, 'Method not allowed.'];
    check('8. GET, PATCH, PUT, DELETE /logout → 405 Method not allowed. (no GET logout)',
        $methods === ['GET' => $notAllowed, 'PATCH' => $notAllowed, 'PUT' => $notAllowed, 'DELETE' => $notAllowed], json_encode($methods));
    check('8. those requests do not end the session', session_exists($store, $keep) && http('GET', '/api/metrics', $keep)['status'] === 200);

    /* 9. Client-supplied data never selects the session --------------------------------------- */
    $victim = plant_session($store, $other, 'admin');
    $userRow = static fn (int $id): array => tk_pdo()->query("SELECT role, status, updated_at, deleted_at FROM users WHERE id = $id")->fetch();
    $victimRow = $userRow($other);
    $bodyOut = logout(null, json_encode(['user_id' => $other, 'role' => 'admin', 'session_id' => $victim, $cookieName => $victim]));
    $queryOut = logout(null, null, "?session_id=$victim&$cookieName=$victim&PHPSESSID=$victim&user_id=$other");
    $cookieOut = logout(null, null, '', ['PHPSESSID' => $victim, 'session_id' => $victim]);
    check('9. anonymous logout naming another session (body, URL, other cookies) → 200 generic', generic($bodyOut, $victim) && generic($queryOut, $victim) && generic($cookieOut, $victim));
    check('9. that other session is untouched and still works', session_exists($store, $victim) && http('GET', '/api/metrics', $victim)['status'] === 200);
    $own = plant_session($store, $analyst, 'analyst');
    $mixed = logout($own, json_encode(['session_id' => $victim, 'user_id' => $other, 'role' => 'viewer']));
    check('9. logout with own cookie + another session in the body: only the cookie session ends', generic($mixed, $victim, $own)
        && !session_exists($store, $own) && session_exists($store, $victim));
    $invalidJson = plant_session($store, $analyst, 'analyst');
    $junk = logout($invalidJson, '{not json');
    check('9. body not JSON: ignored, logout still done (200, session deleted)', generic($junk) && !session_exists($store, $invalidJson));
    check('9. logout never changes the account (role, status, updated_at, deleted_at unchanged)', $userRow($other) === $victimRow
        && $userRow($analyst)['status'] === 'active' && $userRow($analyst)['role'] === 'analyst');
    check('9. another session of the same user is not ended by this logout', session_exists($store, $keep) && http('GET', '/api/metrics', $keep)['status'] === 200);

    /* Public and protected routes unchanged ---------------------------------------------------- */
    check('/health → 200, no session', ($h = http('GET', '/health'))['status'] === 200 && $h['cookies'] === []);
    check('protected route without session → 401, no session', ($p = http('GET', '/api/metrics'))['status'] === 401 && $p['cookies'] === []);
    $viewer = plant_session($store, tk_user('viewer'), 'viewer');
    check('403 unchanged: viewer PATCH → 403', http('PATCH', '/api/incidents/1', $viewer, '{"status":"acknowledged"}')['status'] === 403);
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    array_map('unlink', glob("$store/sess_*") ?: []);
    @rmdir($store);
    tk_cleanup();
}

tk_finish($before);
