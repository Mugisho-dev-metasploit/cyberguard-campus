<?php

declare(strict_types=1);

/**
 * APP-06.8 — incident API through the real front controller (backend/public/index.php):
 * Router, AuthenticationMiddleware, AuthorizationMiddleware, IncidentController, service,
 * repository and database. Served by the PHP built-in server of the running PHP binary, with a
 * private session store where sessions for temporary test users are written (no credentials
 * are needed or created).
 *
 * Covers: GET list/detail per role, 401/404/400 (invalid ids and injection attempts), detail
 * contract and public user shape, sensitive-data scan, read-only guarantee, PATCH permissions
 * (viewer, analyst close, admin close), client-chosen actor/role rejected.
 *
 * Run: /opt/lampp/bin/php backend/tests/IncidentApiTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

const PUBLIC_USER_KEYS = ['id', 'username', 'first_name', 'last_name', 'role'];
const SENSITIVE_KEYS = ['password', 'password_hash', 'email', 'session', 'token', 'metadata', 'secret'];

$before = tk_counts();
$store = sys_get_temp_dir() . '/cg-api-test-' . bin2hex(random_bytes(6));
mkdir($store, 0700);
$server = null;
$canary = 'CANARY-' . bin2hex(random_bytes(4));

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

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

/** @return array{0: int, 1: string, 2: mixed} status, raw body, decoded JSON */
function http(string $method, string $path, ?string $sid = null, ?array $body = null): array
{
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\n";
    if ($sid !== null) {
        $headers .= 'Cookie: ' . ($_ENV['SESSION_NAME'] ?? 'cyberguard_session') . "=$sid\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $body === null ? '' : json_encode($body),
        'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $raw = (string) @file_get_contents($GLOBALS['base'] . $path, false, $context);
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), $raw, json_decode($raw, true)];
}

/** @return list<string> paths of sensitive keys or values found anywhere in the payload */
function sensitive(mixed $value, string $canary, string $path = '$'): array
{
    $hits = [];

    if (is_array($value)) {
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), SENSITIVE_KEYS, true)) {
                $hits[] = "$path.$key";
            }
            array_push($hits, ...sensitive($child, $canary, "$path.$key"));
        }
    } elseif (is_string($value)) {
        foreach ([$canary, 'SQLSTATE', '$2y$', '/opt/', 'PDOException'] as $needle) {
            if (stripos($value, $needle) !== false) {
                $hits[] = "$path=~$needle";
            }
        }
    }

    return $hits;
}

function is_public_user(mixed $user): bool
{
    return $user === null || (is_array($user) && array_keys($user) === PUBLIC_USER_KEYS);
}

try {
    // Server: the real front controller, private session store.
    $port = free_port();
    $GLOBALS['base'] = "http://127.0.0.1:$port/index.php";
    $server = proc_open([PHP_BINARY, '-d', "session.save_path=$store", '-d', 'display_errors=0', '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    $uid = ['viewer' => tk_user('viewer'), 'analyst' => tk_user('analyst'), 'admin' => tk_user('admin')];
    $sess = [];
    foreach ($uid as $role => $id) {
        $sess[$role] = plant_session($store, $id, $role);
    }

    $pdo = tk_pdo();
    $pdo->prepare("INSERT INTO devices (uuid, hostname, ip_address, device_type, environment, status, metadata) VALUES (:u, :h, '10.0.0.8', 'server', 'production', 'online', :m)")
        ->execute(['u' => tk_uuid(), 'h' => tk_marker() . '-host', 'm' => json_encode(['secret' => $canary])]);
    $deviceId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO alerts (alert_uuid, device_id, source, alert_type, severity, title, status, detected_at, metadata) VALUES (:u, :d, 'ids', 'test', 4, :t, 'new', '2026-09-21 07:59:00', :m)")
        ->execute(['u' => tk_uuid(), 'd' => $deviceId, 't' => tk_marker() . ' alert', 'm' => json_encode(['secret' => $canary])]);
    $alertId = (int) $pdo->lastInsertId();

    // History produced by real PATCH calls.
    $id = tk_incident('open', ['assigned_to' => $uid['analyst'], 'alert_id' => $alertId, 'device_id' => $deviceId, 'metadata' => json_encode(['secret' => $canary])]);
    $steps = [['analyst', ['status' => 'acknowledged']], ['analyst', ['status' => 'investigating', 'assigned_to' => $uid['admin']]], ['admin', ['status' => 'contained']]];
    $codes = [];
    foreach ($steps as [$role, $body]) {
        $codes[] = http('PATCH', "/api/incidents/$id", $sess[$role], $body)[0];
    }
    check('setup: three PATCH transitions through HTTP → 200', $codes === [200, 200, 200], json_encode($codes));

    /* GET detail and list per role ---------------------------------------------------------- */
    $payloads = [];
    foreach (['viewer', 'analyst', 'admin'] as $role) {
        [$status, , $json] = http('GET', "/api/incidents/$id", $sess[$role]);
        $payloads[$role] = $json;
        check("$role: GET /api/incidents/{id} → 200", $status === 200 && ($json['success'] ?? null) === true && ($json['message'] ?? null) === 'Incident retrieved successfully.', (string) $status);
        [$status, , $json] = http('GET', '/api/incidents', $sess[$role]);
        check("$role: GET /api/incidents → 200", $status === 200 && is_array($json['data'] ?? null));
    }
    check('detail identical for the three roles', $payloads['viewer'] === $payloads['analyst'] && $payloads['analyst'] === $payloads['admin']);

    $data = $payloads['viewer']['data'] ?? [];
    $incident = $data['incident'] ?? [];
    $history = $data['history'] ?? [];
    check('detail contract: data.incident + data.history', is_array($incident) && is_array($history) && array_keys($data) === ['incident', 'history']);
    check('incident carries assignee, alert, device (public summaries)', ($incident['assignee']['id'] ?? null) === $uid['admin']
        && ($incident['alert']['id'] ?? null) === $alertId && ($incident['device']['id'] ?? null) === $deviceId
        && array_keys($incident['alert']) === ['id', 'alert_uuid', 'title', 'severity', 'status', 'detected_at']
        && array_keys($incident['device']) === ['id', 'uuid', 'hostname', 'ip_address', 'device_type', 'environment', 'status']);
    check('history: 3 entries, oldest first, actor + previous/new assignee users',
        array_map(static fn (array $h): string => $h['previous_status'] . '→' . $h['new_status'], $history) === ['open→acknowledged', 'acknowledged→investigating', 'investigating→contained']
        && array_map(static fn (array $h): ?int => $h['actor']['id'] ?? null, $history) === [$uid['analyst'], $uid['analyst'], $uid['admin']]
        && ($history[1]['previous_assignee_user']['id'] ?? null) === $uid['analyst'] && ($history[1]['new_assignee_user']['id'] ?? null) === $uid['admin']);
    $shapes = array_merge([$incident['assignee']], array_column($history, 'actor'), array_column($history, 'previous_assignee_user'), array_column($history, 'new_assignee_user'));
    check('every user object is exactly {id, username, first_name, last_name, role}', array_filter($shapes, static fn ($u): bool => !is_public_user($u)) === []);
    $hits = array_merge(sensitive($payloads['viewer'], $canary), sensitive(http('GET', '/api/incidents', $sess['viewer'])[2], $canary));
    check('no password, password_hash, email, session, token, secret, metadata (keys) nor canary/hash/SQLSTATE (values)', $hits === [], implode(', ', array_slice($hits, 0, 5)));

    /* Authentication ------------------------------------------------------------------------ */
    $expired = plant_session($store, $uid['admin'], 'admin', time() - 21600);
    foreach (['no session' => null, 'expired session' => $expired, 'forged session id' => 'tk' . str_repeat('0', 32)] as $label => $sid) {
        $get = http('GET', "/api/incidents/$id", $sid);
        $patch = http('PATCH', "/api/incidents/$id", $sid, ['title' => 'x']);
        check("$label → 401 on GET and PATCH", $get[0] === 401 && $patch[0] === 401 && ($get[2]['message'] ?? '') === 'Authentication required.');
    }

    /* IDs: not found, invalid, injection ---------------------------------------------------- */
    $cases = ['999999999' => 404, '0' => 404, 'abc' => 400, '1abc' => 400, '1.5' => 400, '-1' => 400,
        rawurlencode('1 OR 1=1') => 400, rawurlencode("1' OR '1'='1") => 400, rawurlencode('1;DROP TABLE incidents') => 400,
        rawurlencode('1 UNION SELECT password_hash FROM users') => 400];
    $wrong = [];
    foreach ($cases as $raw => $expected) {
        [$status, $body, $json] = http('GET', "/api/incidents/$raw", $sess['viewer']);
        $message = $json['message'] ?? '';
        $want = $expected === 404 ? 'Incident not found.' : 'Invalid incident identifier.';
        if ($status !== $expected || $message !== $want || sensitive($json, $canary) !== [] || stripos($body, 'users') !== false) {
            $wrong[] = rawurldecode((string) $raw) . " → $status";
        }
    }
    check('ids: unknown/0 → 404, abc/1abc/1.5/-1/injection → 400, fixed messages, no leak', $wrong === [], implode('; ', $wrong));
    check('GET /api/incidents/ (empty id) → 404 route not found', http('GET', '/api/incidents/', $sess['viewer'])[0] === 404);
    check('injection attempts left the tables intact', tk_history($id) !== [] && tk_row($id)['status'] === 'contained'
        && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before['users'] + 3);

    /* Read-only guarantee ----------------------------------------------------------------------- */
    $snapshot = tk_counts();
    $updatedAt = tk_row($id)['updated_at'];
    for ($i = 0; $i < 3; $i++) {
        foreach (['viewer', 'analyst', 'admin'] as $role) {
            http('GET', "/api/incidents/$id", $sess[$role]);
            http('GET', '/api/incidents', $sess[$role]);
        }
    }
    check('18 GETs: no row added or removed in any table, updated_at unchanged', tk_counts() === $snapshot && tk_row($id)['updated_at'] === $updatedAt);

    /* PATCH permissions and client-chosen actor/role ------------------------------------------ */
    $target = tk_incident('resolved');
    $initial = tk_row($target);
    [$status, , $json] = http('PATCH', "/api/incidents/$target", $sess['viewer'], ['title' => 'viewer edit']);
    check('viewer PATCH → 403 Insufficient permissions., nothing written', $status === 403 && ($json['message'] ?? '') === 'Insufficient permissions.' && tk_row($target) === $initial && tk_history($target) === []);
    [$status, , $json] = http('PATCH', "/api/incidents/$target", $sess['analyst'], ['status' => 'closed']);
    check('analyst close → 403 Insufficient permissions., nothing written', $status === 403 && ($json['message'] ?? '') === 'Insufficient permissions.' && tk_row($target) === $initial && tk_history($target) === []);
    foreach (['user_id' => 999, 'role' => 'admin'] as $field => $value) {
        [$status, , $json] = http('PATCH', "/api/incidents/$target", $sess['analyst'], ['status' => 'closed', $field => $value]);
        check("client '$field' → 422 Unknown field, nothing written", $status === 422 && str_contains($json['message'] ?? '', "Unknown field(s): $field") && tk_row($target) === $initial);
    }
    [$status, , $json] = http('PATCH', "/api/incidents/$target", $sess['admin'], ['status' => 'closed']);
    $h = tk_history($target);
    check('admin close → 200, closed_at set, history actor = the session user', $status === 200 && ($json['data']['status'] ?? '') === 'closed'
        && tk_row($target)['closed_at'] !== null && count($h) === 1 && (int) $h[0]['user_id'] === $uid['admin']);
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
