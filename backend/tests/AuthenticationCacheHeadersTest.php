<?php

declare(strict_types=1);

/**
 * APP-07.4.6 — sign-in and sign-out answers are never stored by a browser or proxy.
 *
 * Every response of POST /login (200, 401, 429, 500) and POST /logout carries exactly one
 * "Cache-Control: no-store" and one "Pragma: no-cache", while Content-Type, Set-Cookie and
 * Retry-After stay as they were. Other routes are not changed (GET /health, protected 401).
 *
 * Checked over HTTP on the real front controller: a private PHP server with the configured
 * session cache limiter, the same with the limiter disabled (the headers must not depend on
 * PHP's session settings), the running Apache, and a private server whose session store cannot
 * be used (sign-in 500, APP-07.4.5). Temporary user with a random password for this run;
 * everything created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/AuthenticationCacheHeadersTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-cache-headers-' . bin2hex(random_bytes(6));
mkdir("$dir/store", 0700, true);
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
$servers = [];

/**
 * @return array{status: int, json: mixed, headers: array<string, list<string>>} header names lower-cased, every occurrence kept
 */
function call(string $url, string $method, ?array $body = null, ?string $cookie = null): array
{
    $h = "Content-Type: application/json\r\n" . ($cookie !== null ? "Cookie: $cookie\r\n" : '');
    $c = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true, 'timeout' => 30]]);
    $raw = (string) @file_get_contents($url, false, $c);
    $headers = [];
    foreach (array_slice($http_response_header ?? [], 1) as $line) {
        [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        $headers[strtolower($name)][] = $value;
    }
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return ['status' => (int) ($m[1] ?? 0), 'json' => json_decode($raw, true), 'headers' => $headers];
}

/** Exactly one Cache-Control: no-store and one Pragma: no-cache, nothing contradictory. */
function no_store(array $r): bool
{
    $h = $r['headers'];

    return ($h['cache-control'] ?? []) === ['no-store'] && ($h['pragma'] ?? []) === ['no-cache']
        && !preg_match('/public|max-age|s-maxage|immutable/i', implode(' ', $h['cache-control'] ?? []))
        && array_filter($h['expires'] ?? [], static fn (string $e): bool => strtotime($e) === false || strtotime($e) > time()) === [];
}

function json_type(array $r): bool
{
    return ($r['headers']['content-type'] ?? []) === ['application/json; charset=utf-8'];
}

/** The session cookie the browser ends up with (last Set-Cookie wins): value, or null when deleted/absent. */
function session_cookie(array $r, string $name): ?string
{
    $value = null;
    foreach ($r['headers']['set-cookie'] ?? [] as $c) {
        if (preg_match('/^' . preg_quote($name, '/') . '=([^;]*)/', $c, $m) === 1) {
            $value = $m[1] === 'deleted' || $m[1] === '' ? null : $m[1];
        }
    }

    return $value;
}

function start_server(string $dir, array $settings): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $args = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$dir/server.log"];
    foreach ($settings as $k => $v) {
        array_push($args, '-d', "$k=$v");
    }
    $server = proc_open([...$args, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    return [$server, "http://127.0.0.1:$port/index.php"];
}

try {
    $password = bin2hex(random_bytes(16));
    $id = tk_user('analyst');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $id]);
    $username = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $id")->fetchColumn();
    $hash = (string) tk_pdo()->query("SELECT password_hash FROM users WHERE id = $id")->fetchColumn();

    [$s1, $u1] = $servers[] = start_server($dir, ['session.save_path' => "$dir/store"]);
    [$s2, $u2] = $servers[] = start_server($dir, ['session.save_path' => "$dir/store", 'session.cache_limiter' => '']);
    $apache = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/') . '/backend/public/index.php';
    $targets = ['private server' => $u1, 'private server, PHP session cache limiter off' => $u2, 'Apache' => $apache];
    $all = [];

    foreach ($targets as $where => $base) {
        /* T1 — successful sign-in */
        $ok = call("$base/login", 'POST', ['identifier' => $username, 'password' => $password]);
        $sid = session_cookie($ok, $cookieName);
        check("T1 $where, POST /login 200: no-store + no-cache, JSON, session cookie issued (HttpOnly, SameSite=Lax)", $ok['status'] === 200
            && ($ok['json']['success'] ?? null) === true && no_store($ok) && json_type($ok) && $sid !== null
            && preg_match('/HttpOnly/i', end($ok['headers']['set-cookie'])) === 1 && preg_match('/SameSite=Lax/i', end($ok['headers']['set-cookie'])) === 1,
            json_encode($ok['headers']['cache-control'] ?? null));

        /* T2 — invalid credentials (and a request refused before any check) */
        $bad = call("$base/login", 'POST', ['identifier' => $username, 'password' => 'wrong-' . bin2hex(random_bytes(6))]);
        $empty = call("$base/login", 'POST', ['identifier' => '', 'password' => '']);
        check("T2 $where, POST /login 401: same body, no-store + no-cache, JSON, no cookie", $bad['status'] === 401
            && $bad['json'] === ['success' => false, 'message' => 'Invalid credentials.'] && no_store($bad) && json_type($bad) && !isset($bad['headers']['set-cookie']));
        check("T2 $where, POST /login 401 (empty fields): no-store + no-cache", $empty['status'] === 401 && no_store($empty));

        /* T3 — throttled */
        $throttled = tk_marker() . '_t3_' . count($all);
        for ($i = 0; $i < 5; $i++) {
            call("$base/login", 'POST', ['identifier' => $throttled, 'password' => 'w' . $i]);
        }
        $r429 = call("$base/login", 'POST', ['identifier' => $throttled, 'password' => 'w']);
        check("T3 $where, POST /login 429: no-store + no-cache, Retry-After kept (one, 1..30 s)", $r429['status'] === 429 && no_store($r429)
            && count($r429['headers']['retry-after'] ?? []) === 1 && (int) $r429['headers']['retry-after'][0] >= 1 && (int) $r429['headers']['retry-after'][0] <= 30
            && json_type($r429));

        /* T5 — sign-out with a valid session; the session really ends */
        $out = call("$base/logout", 'POST', null, "$cookieName=$sid");
        check("T5 $where, POST /logout 200 (valid session): no-store + no-cache, cookie deleted", $out['status'] === 200
            && $out['json'] === ['success' => true, 'message' => 'Logged out successfully.'] && no_store($out)
            && session_cookie($out, $cookieName) === null && isset($out['headers']['set-cookie']));
        check("T5 $where, the ended session is refused afterwards (401)", call("$base/api/metrics", 'GET', null, "$cookieName=$sid")['status'] === 401);

        /* T6 — sign-out without a session, and with an unknown one */
        $none = call("$base/logout", 'POST');
        $unknown = call("$base/logout", 'POST', null, "$cookieName=tk" . str_repeat('0', 32));
        check("T6 $where, POST /logout 200 (no session): same body, no-store + no-cache, no cookie", $none['status'] === 200
            && $none['json'] === ['success' => true, 'message' => 'Logged out successfully.'] && no_store($none) && !isset($none['headers']['set-cookie']));
        check("T6 $where, POST /logout 200 (unknown session): no-store + no-cache", $unknown['status'] === 200 && no_store($unknown));

        /* Scope — other routes unchanged */
        $health = call("$base/health", 'GET');
        $metrics = call("$base/api/metrics", 'GET');
        check("scope $where: GET /health and a protected 401 carry no Cache-Control/Pragma added by this change", $health['status'] === 200
            && $metrics['status'] === 401 && !isset($health['headers']['cache-control'], $health['headers']['pragma'])
            && !isset($metrics['headers']['cache-control'], $metrics['headers']['pragma']));

        array_push($all, $ok, $bad, $empty, $r429, $out, $none, $unknown);
    }

    /* T4 — internal error while establishing the session (APP-07.4.5): 500, same headers */
    [$s3, $u3] = $servers[] = start_server($dir, ['session.save_path' => "$dir/missing-directory"]);
    $r500 = call("$u3/login", 'POST', ['identifier' => $username, 'password' => $password]);
    check('T4 POST /login 500 (session store unusable): no-store + no-cache, generic body', $r500['status'] === 500
        && $r500['json'] === ['success' => false, 'message' => 'Unable to sign in.'] && no_store($r500) && json_type($r500));
    $all[] = $r500;

    /* T7 — no duplicate or contradictory caching header anywhere */
    $dupes = array_filter($all, static fn (array $r): bool => count($r['headers']['cache-control'] ?? []) !== 1 || count($r['headers']['pragma'] ?? []) !== 1
        || count($r['headers']['content-type'] ?? []) !== 1);
    check('T7 every auth response: exactly one Cache-Control, one Pragma, one Content-Type; no public/max-age; no future Expires',
        $dupes === [] && array_filter($all, static fn (array $r): bool => !no_store($r)) === [], (string) count($dupes));

    /* T8 — nothing sensitive in the headers */
    $text = json_encode(array_map(static function (array $r): array {
        $h = $r['headers'];
        unset($h['set-cookie']);   // session IDs belong in Set-Cookie only

        return $h;
    }, $all));
    check('T8 no password, hash, path, exception or session ID in the response headers (outside Set-Cookie)', !str_contains($text, $password)
        && !preg_match('/\$2[aby]\$|\/opt\/|\/tmp\/|Exception|SQLSTATE/i', $text) && !preg_match('/[0-9a-z]{26,}/', str_replace(['charset=utf-8'], '', $text)));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    foreach ($servers as [$server]) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    foreach (glob("$dir/store/*") ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob("$dir/*") ?: [] as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
