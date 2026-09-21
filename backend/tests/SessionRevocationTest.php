<?php

declare(strict_types=1);

/**
 * APP-07.3.1 — session lifecycle and access revocation.
 *
 * Real front controller (backend/public/index.php) over HTTP, on a private PHP server and a
 * private session store. Sessions are written to that store for temporary test users (no
 * credentials exist or are needed); the users carry the per-run marker and are deleted at
 * the end. Every protected request must re-read the account: a deleted or no longer active
 * account gets 401 and its session is destroyed; authorization uses the role in the database.
 *
 * Run: /opt/lampp/bin/php backend/tests/SessionRevocationTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$store = sys_get_temp_dir() . '/cg-revocation-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);
$server = null;
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';

const AUTH_REQUIRED = ['success' => false, 'message' => 'Authentication required.'];
const FORBIDDEN = ['success' => false, 'message' => 'Insufficient permissions.'];

function plant_session(string $store, int $userId, string $role): string
{
    $sid = 'tk' . bin2hex(random_bytes(16));
    $data = ['user_id' => $userId, 'user_uuid' => tk_uuid(), 'role' => $role, 'authenticated' => true, '__session_started' => time() - 60];
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

function stored_role(string $store, string $sid): ?string
{
    clearstatcache();
    $raw = is_file("$store/sess_$sid") ? (string) file_get_contents("$store/sess_$sid") : '';

    return preg_match('/(?:^|;|})role\|s:\d+:"([^"]*)";/', $raw, $m) === 1 ? $m[1] : null;
}

function store_files(string $store): int
{
    clearstatcache();

    return count(glob("$store/sess_*") ?: []);
}

/**
 * @param array<string, string> $cookies extra cookies
 * @return array{status: int, json: mixed, cookies: list<string>} cookies = Set-Cookie header values
 */
function http(string $method, string $path, ?string $sid = null, ?array $body = null, array $cookies = [], string $headers = ''): array
{
    global $cookieName;

    if ($sid !== null) {
        $cookies = [$cookieName => $sid] + $cookies;
    }
    $headers .= "Accept: application/json\r\nContent-Type: application/json\r\n";
    if ($cookies !== []) {
        $headers .= 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => "$k=$v", array_keys($cookies), $cookies)) . "\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $raw = (string) @file_get_contents($GLOBALS['base'] . $path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);
    $setCookies = [];
    foreach ($http_response_header ?? [] as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $setCookies[] = trim(substr($line, 11));
        }
    }

    return ['status' => (int) ($m[1] ?? 0), 'json' => json_decode($raw, true), 'cookies' => $setCookies];
}

/** True when the response tells the browser to drop the session cookie. */
function clears_cookie(array $response): bool
{
    global $cookieName;

    foreach ($response['cookies'] as $cookie) {
        if (str_starts_with($cookie, "$cookieName=") && preg_match('/expires=([^;]+)/i', $cookie, $m) === 1 && strtotime($m[1]) < time()) {
            return true;
        }
    }

    return false;
}

function set_user(int $id, string $column, ?string $value): void
{
    $sql = $column === 'deleted_at' ? 'UPDATE users SET deleted_at = NOW(6) WHERE id = :id' : "UPDATE users SET `$column` = :v WHERE id = :id";
    $params = $column === 'deleted_at' ? ['id' => $id] : ['id' => $id, 'v' => $value];
    tk_pdo()->prepare($sql)->execute($params);
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

    $viewer = tk_user('viewer');
    $analyst = tk_user('analyst');
    $admin = tk_user('admin');

    /* 1. Active account: allowed according to its role --------------------------------------- */
    $sViewer = plant_session($store, $viewer, 'viewer');
    $sAnalyst = plant_session($store, $analyst, 'analyst');
    $sAdmin = plant_session($store, $admin, 'admin');
    $open = tk_incident('open');
    $list = http('GET', '/api/incidents', $sViewer);
    $detail = http('GET', "/api/incidents/$open", $sViewer);
    $viewerPatch = http('PATCH', "/api/incidents/$open", $sViewer, ['status' => 'acknowledged']);
    $analystPatch = http('PATCH', "/api/incidents/$open", $sAnalyst, ['status' => 'acknowledged']);
    check('1. active viewer: GET list and detail → 200', $list['status'] === 200 && $detail['status'] === 200);
    check('1. active viewer: PATCH → 403 (role, not account)', $viewerPatch['status'] === 403 && $viewerPatch['json'] === FORBIDDEN);
    check('1. active analyst: PATCH transition → 200', $analystPatch['status'] === 200 && ($analystPatch['json']['data']['status'] ?? '') === 'acknowledged');
    check('1. active sessions are kept (not destroyed, cookie not cleared)', session_exists($store, $sViewer) && session_exists($store, $sAnalyst)
        && !clears_cookie($list) && !clears_cookie($analystPatch));

    /* 2–4. Revocation: inactive, locked, hard-deleted, soft-deleted --------------------------- */
    $revoked = [];
    foreach (['inactive' => ['status', 'inactive'], 'locked' => ['status', 'locked'], 'soft-deleted' => ['deleted_at', null], 'deleted' => null] as $label => $change) {
        $id = tk_user('admin');
        $sid = plant_session($store, $id, 'admin');
        $ok = http('GET', '/api/incidents', $sid)['status'] === 200;
        if ($change === null) {
            tk_pdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        } else {
            set_user($id, $change[0], $change[1]);
        }
        $get = http('GET', '/api/incidents', $sid);
        $destroyed = !session_exists($store, $sid);
        $cleared = clears_cookie($get);
        $patch = http('PATCH', "/api/incidents/$open", $sid, ['status' => 'investigating']);
        $revoked[$label] = compact('id', 'sid', 'ok', 'get', 'destroyed', 'cleared', 'patch');
        check("2–3. $label account: next protected request → 401 with the generic body", $ok && $get['status'] === 401 && $get['json'] === AUTH_REQUIRED,
            json_encode([$ok, $get['status'], $get['json']]));
        check("4. $label account: session deleted from the server store and cookie cleared", $destroyed && $cleared);
        check("4. $label account: the revoked session cannot write either (PATCH → 401)", $patch['status'] === 401 && $patch['json'] === AUTH_REQUIRED);
    }
    check('2. inactive account never gets 403 (no hint that the session was otherwise valid)', $revoked['inactive']['get']['status'] !== 403);
    check('incident untouched by the revoked sessions', tk_row($open)['status'] === 'acknowledged');

    set_user($revoked['inactive']['id'], 'status', 'active');
    $replay = http('GET', '/api/incidents', $revoked['inactive']['sid']);
    check('4. reactivating the account does not revive the revoked session (401)', $replay['status'] === 401 && !session_exists($store, $revoked['inactive']['sid']));

    $unknown = http('GET', '/api/incidents', 'tk' . str_repeat('0', 32));
    $bodies = array_map(static fn (array $r): mixed => $r['get']['json'], $revoked);
    check('no information leak: inactive, locked, deleted, soft-deleted and unknown session get the identical response',
        count(array_unique(array_map('json_encode', [...array_values($bodies), $unknown['json']]))) === 1 && $unknown['status'] === 401);

    /* 5. Role changed in the database: applied on the next request ---------------------------- */
    $mover = tk_user('analyst');
    $sMover = plant_session($store, $mover, 'analyst');
    $i5 = tk_incident('acknowledged');
    set_user($mover, 'role', 'viewer');
    $demoted = http('PATCH', "/api/incidents/$i5", $sMover, ['status' => 'investigating']);
    check('5. analyst demoted to viewer in DB → next PATCH 403', $demoted['status'] === 403 && $demoted['json'] === FORBIDDEN && tk_row($i5)['status'] === 'acknowledged');
    check('5. session role refreshed to the database role (viewer), session kept', stored_role($store, $sMover) === 'viewer');
    set_user($mover, 'role', 'admin');
    $i5b = tk_incident('resolved');
    $promoted = http('PATCH', "/api/incidents/$i5b", $sMover, ['status' => 'closed']);
    $history = tk_history($i5b);
    check('5. promoted to admin in DB → close allowed (admin-only) on the next request, actor = session user',
        $promoted['status'] === 200 && tk_row($i5b)['status'] === 'closed' && (int) (end($history)['user_id'] ?? 0) === $mover);
    $stale = tk_user('viewer');
    $sStale = plant_session($store, $stale, 'admin'); // session recorded as admin, account is viewer
    $i5c = tk_incident('resolved');
    $staleClose = http('PATCH', "/api/incidents/$i5c", $sStale, ['status' => 'closed']);
    check('5. stale elevated role in the session (admin) is ignored: DB viewer → 403', $staleClose['status'] === 403 && tk_row($i5c)['status'] === 'resolved');

    /* 6. The client cannot choose its role ---------------------------------------------------- */
    $i6 = tk_incident('resolved');
    $spoofs = [
        'body' => http('PATCH', "/api/incidents/$i6", $sViewer, ['status' => 'closed', 'role' => 'admin', 'user_role' => 'admin']),
        'query' => http('PATCH', "/api/incidents/$i6?role=admin", $sViewer, ['status' => 'closed']),
        'cookie' => http('PATCH', "/api/incidents/$i6", $sViewer, ['status' => 'closed'], ['role' => 'admin', 'user_role' => 'admin']),
        'header' => http('PATCH', "/api/incidents/$i6", $sViewer, ['status' => 'closed'], [], "X-User-Role: admin\r\nX-Role: admin\r\n"),
    ];
    check('6. viewer sending role=admin in body, query, cookie or header → 403',
        array_map(static fn (array $r): int => $r['status'], $spoofs) === ['body' => 403, 'query' => 403, 'cookie' => 403, 'header' => 403]);
    $analystBody = http('PATCH', "/api/incidents/$i6", $sAnalyst, ['status' => 'closed', 'role' => 'admin']);
    $analystQuery = http('PATCH', "/api/incidents/$i6?role=admin", $sAnalyst, ['status' => 'closed'], ['role' => 'admin'], "X-User-Role: admin\r\n");
    check('6. analyst sending role=admin in the body → 422 unknown field (APP-06 allow-list)', $analystBody['status'] === 422);
    check('6. analyst sending role=admin in query, cookie and header cannot close (admin-only) → 403',
        $analystQuery['status'] === 403 && $analystQuery['json'] === FORBIDDEN && tk_row($i6)['status'] === 'resolved');
    check('6. session role unchanged by client input (viewer, analyst)', stored_role($store, $sViewer) === 'viewer' && stored_role($store, $sAnalyst) === 'analyst');

    /* 7. Anonymous protected request: 401 and no session created ------------------------------ */
    $files = store_files($store);
    $anon = http('GET', '/api/incidents');
    $anonPatch = http('PATCH', "/api/incidents/$open", null, ['status' => 'investigating']);
    check('7. anonymous GET and PATCH → 401 with the generic body', $anon['status'] === 401 && $anon['json'] === AUTH_REQUIRED && $anonPatch['status'] === 401);
    check('7. anonymous request: no session cookie issued, no session stored', $anon['cookies'] === [] && $anonPatch['cookies'] === [] && store_files($store) === $files);
    $forged = http('GET', '/api/incidents', 'forged' . bin2hex(random_bytes(8)));
    // PHP strict mode swaps an unknown ID for a new one; that session is destroyed in the same
    // request, and the last Set-Cookie (the one the browser keeps) deletes the cookie.
    $lastSessionCookie = array_values(array_filter($forged['cookies'], static fn (string $c): bool => str_starts_with($c, "$cookieName=")));
    check('7. unknown session ID → 401, nothing left in the store, session cookie ends up cleared', $forged['status'] === 401
        && store_files($store) === $files && $lastSessionCookie !== [] && clears_cookie(['cookies' => [end($lastSessionCookie)]]));

    /* 8. Public routes ------------------------------------------------------------------------ */
    $health = http('GET', '/health');
    $login = http('POST', '/login', null, ['identifier' => tk_marker() . '_nobody', 'password' => bin2hex(random_bytes(8))]);
    $healthRevoked = http('GET', '/health', $revoked['deleted']['sid']);
    check('8. GET /health → 200 without authentication, no session', $health['status'] === 200 && ($health['json']['status'] ?? '') === 'healthy' && $health['cookies'] === []);
    check('8. POST /login reachable anonymously (unknown user → 401 Invalid credentials), no session', $login['status'] === 401
        && $login['json'] === ['success' => false, 'message' => 'Invalid credentials.'] && store_files($store) === $files);
    check('8. GET /health with a revoked session cookie → 200', $healthRevoked['status'] === 200);

    /* 9. APP-06 incident authorization unchanged ---------------------------------------------- */
    $i9 = tk_incident('resolved');
    $analystClose = http('PATCH', "/api/incidents/$i9", $sAnalyst, ['status' => 'closed']);
    $adminClose = http('PATCH', "/api/incidents/$i9", $sAdmin, ['status' => 'closed']);
    $h9 = tk_history($i9);
    check('9. analyst close → 403, admin close → 200 (APP-06 rule)', $analystClose['status'] === 403 && $adminClose['status'] === 200
        && tk_row($i9)['status'] === 'closed' && tk_row($i9)['closed_at'] !== null);
    check('9. history actor = the admin session user', (int) (end($h9)['user_id'] ?? 0) === $admin);
    $invalid = http('PATCH', "/api/incidents/$i9", $sAdmin, ['status' => 'open']);
    check('9. invalid transition still refused for an active admin (422)', $invalid['status'] === 422);
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
