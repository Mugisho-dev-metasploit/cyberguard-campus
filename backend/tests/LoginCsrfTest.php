<?php

declare(strict_types=1);

/**
 * APP074-05 — login CSRF; APP074-16 — the sign-in form never puts credentials in a URL.
 *
 * POST /login accepts only a JSON body (415 otherwise) and refuses a browser-declared foreign
 * Origin (403). The body types a cross-site HTML form can send without a CORS preflight
 * (text/plain, multipart/form-data, application/x-www-form-urlencoded) therefore never sign
 * anyone in, even with valid credentials. Refusals consume no throttle budget and carry
 * no-store. POST /logout is unchanged (the frontend sends it without a body). The sign-in form
 * submits with POST, so a submission without JavaScript does not reach the URL or access logs.
 *
 * Over HTTP: a private PHP server and the running Apache. Temporary user with a random password
 * for this run; everything created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/LoginCsrfTest.php   (exit 0 = all passed)
 */

require __DIR__ . '/support/bootstrap.php';

$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-csrf-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
$server = null;

/** @return array{status: int, json: mixed, sid: ?string, headers: array<string, string>} */
function post(string $url, string $body, ?string $contentType, array $extra = [], ?string $cookie = null): array
{
    global $cookieName;
    $h = ($contentType !== null ? "Content-Type: $contentType\r\n" : '') . ($cookie !== null ? "Cookie: $cookie\r\n" : '');
    foreach ($extra as $k => $v) {
        $h .= "$k: $v\r\n";
    }
    $c = stream_context_create(['http' => ['method' => 'POST', 'header' => $h, 'content' => $body, 'ignore_errors' => true, 'timeout' => 30]]);
    $raw = (string) @file_get_contents($url, false, $c);
    $sid = null;
    $headers = [];
    foreach (array_slice($http_response_header ?? [], 1) as $l) {
        [$name, $value] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
        if (preg_match('/^' . preg_quote($cookieName, '/') . '=([^;]*)/', $value, $m) === 1 && strtolower($name) === 'set-cookie') {
            $sid = $m[1] === 'deleted' || $m[1] === '' ? null : $m[1];
        } else {
            $headers[strtolower($name)] = $value;
        }
    }
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $mm);

    return ['status' => (int) ($mm[1] ?? 0), 'json' => json_decode($raw, true), 'sid' => $sid, 'headers' => $headers];
}

function buckets(): int
{
    return (int) tk_pdo()->query('SELECT COUNT(*) FROM login_throttle')->fetchColumn();
}

try {
    $password = bin2hex(random_bytes(16));
    $id = tk_user('viewer');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $id]);
    $username = (string) tk_pdo()->query("SELECT username FROM users WHERE id = $id")->fetchColumn();
    $json = json_encode(['identifier' => $username, 'password' => $password]);
    $boundary = '----cg' . bin2hex(random_bytes(4));
    $multipart = "--$boundary\r\nContent-Disposition: form-data; name=\"identifier\"\r\n\r\n$username\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"password\"\r\n\r\n$password\r\n--$boundary--\r\n";

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', "session.save_path=$dir", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
    $apacheBase = rtrim((string) (getenv('CG_BASE_URL') ?: 'http://localhost/cyberguard-campus'), '/');
    $targets = [
        'private server' => ["http://127.0.0.1:$port/index.php", "http://127.0.0.1:$port"],
        'Apache' => ["$apacheBase/backend/public/index.php", (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $apacheBase)],
    ];

    foreach ($targets as $where => [$base, $origin]) {
        $url = "$base/login";

        /* Cross-site form body types, with VALID credentials: never a sign-in */
        $buckets = buckets();
        $forms = [
            'text/plain (JSON-shaped)' => post($url, $json, 'text/plain'),
            'multipart/form-data' => post($url, $multipart, "multipart/form-data; boundary=$boundary"),
            'application/x-www-form-urlencoded' => post($url, http_build_query(['identifier' => $username, 'password' => $password]), 'application/x-www-form-urlencoded'),
            'no Content-Type' => post($url, $json, null),
        ];
        foreach ($forms as $label => $r) {
            check("$where: $label with valid credentials → 415, no session, no-store", $r['status'] === 415
                && $r['json'] === ['success' => false, 'message' => 'Unsupported content type.'] && $r['sid'] === null
                && ($r['headers']['cache-control'] ?? '') === 'no-store', (string) $r['status']);
        }

        /* Origin */
        $foreign = post($url, $json, 'application/json', ['Origin' => 'http://attacker.example']);
        $opaque = post($url, $json, 'application/json', ['Origin' => 'null']);
        $otherPort = post($url, $json, 'application/json', ['Origin' => $origin . ':1']);
        foreach (['foreign Origin' => $foreign, 'opaque Origin "null"' => $opaque, 'same host, other port' => $otherPort] as $label => $r) {
            check("$where: JSON, valid credentials, $label → 403, no session", $r['status'] === 403
                && $r['json'] === ['success' => false, 'message' => 'Cross-origin sign-in refused.'] && $r['sid'] === null);
        }
        check("$where: refused requests consumed no throttle budget", buckets() === $buckets);

        /* Legitimate requests unchanged */
        $same = post($url, $json, 'application/json; charset=utf-8', ['Origin' => $origin]);
        check("$where: JSON (with charset) from the same origin → 200 with a session", $same['status'] === 200 && ($same['json']['success'] ?? null) === true && $same['sid'] !== null);
        $noOrigin = post($url, $json, 'application/json');
        check("$where: JSON without Origin (non-browser client) → 200", $noOrigin['status'] === 200 && $noOrigin['sid'] !== null);
        $wrong = post($url, json_encode(['identifier' => $username, 'password' => 'wrong-' . bin2hex(random_bytes(4))]), 'application/json', ['Origin' => $origin]);
        check("$where: JSON, wrong password → 401 Invalid credentials. (unchanged)", $wrong['status'] === 401
            && $wrong['json'] === ['success' => false, 'message' => 'Invalid credentials.']);

        /* Logout unchanged: the frontend sends it without body or Content-Type */
        foreach ([$same['sid'], $noOrigin['sid']] as $sid) {
            $out = post("$base/logout", '', null, ['Origin' => $origin], "$cookieName=$sid");
            check("$where: POST /logout without Content-Type still → 200 Logged out", $out['status'] === 200
                && $out['json'] === ['success' => true, 'message' => 'Logged out successfully.']);
        }
    }

    /* APP074-16 — the form submits with POST: without JavaScript nothing goes into the URL */
    $html = (string) file_get_contents(dirname(__DIR__, 2) . '/frontend/login.html');
    check('APP074-16: the sign-in form declares method="post"', preg_match('/<form[^>]*id="login-form"[^>]*method="post"/i', $html) === 1);
    $marker = 'cgformcheck' . bin2hex(random_bytes(6));
    $accessLog = '/opt/lampp/logs/access_log';
    clearstatcache();
    $offset = is_file($accessLog) ? filesize($accessLog) : 0;
    $static = post("$apacheBase/frontend/login.html", http_build_query(['identifier' => $marker, 'password' => $marker]), 'application/x-www-form-urlencoded');
    usleep(300000);
    clearstatcache();
    $newLines = is_file($accessLog) ? (string) file_get_contents($accessLog, false, null, $offset) : '';
    check('APP074-16: a POST fallback to login.html is served (200) and the values stay out of the access log', $static['status'] === 200
        && str_contains($newLines, 'POST /cyberguard-campus/frontend/login.html') && !str_contains($newLines, $marker));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
