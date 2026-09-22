<?php

declare(strict_types=1);

/**
 * APP074-11 — authentication events in audit_logs.
 *
 * Over HTTP (real front controller, private PHP server and session store): sign-in success,
 * failures (wrong password, unknown identifier, inactive account: identical rows, no account,
 * no identifier), throttling (at most one row per address and minute), sign-out, session
 * revocation (inactive and hard-deleted account). Every stored row is scanned for passwords,
 * identifiers, hashes and session IDs. In process: invalid client address, audit failure never
 * changes the outcome. Temporary users with random passwords; every row created is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/AuthenticationAuditTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\AuditLogRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationAudit;
use CyberGuard\Campus\Services\AuthenticationService;

require __DIR__ . '/support/bootstrap.php';

ob_start();
$before = tk_counts();
$dir = sys_get_temp_dir() . '/cg-audit-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$cookieName = $_ENV['SESSION_NAME'] ?? 'cyberguard_session';
$server = null;
$start = (int) tk_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();

/** Audit rows written since the start of this test (optionally one action). */
function audit_rows(int $start, ?string $action = null): array
{
    $sql = 'SELECT id, user_id, action, resource_type, resource_id, ip_address, user_agent, success, details FROM audit_logs WHERE id > :start'
        . ($action !== null ? ' AND action = :action' : '') . ' ORDER BY id';
    $s = tk_pdo()->prepare($sql);
    $s->execute($action !== null ? ['start' => $start, 'action' => $action] : ['start' => $start]);

    return $s->fetchAll();
}

/** @return array{status: int, sid: ?string} */
function req(string $method, string $path, ?array $body = null, ?string $sid = null, string $agent = 'cg-audit-test'): array
{
    global $cookieName;
    $h = "Content-Type: application/json\r\nUser-Agent: $agent\r\n" . ($sid !== null ? "Cookie: $cookieName=$sid\r\n" : '');
    $c = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 30]]);
    @file_get_contents($GLOBALS['base'] . $path, false, $c);
    $issued = null;
    foreach ($http_response_header ?? [] as $l) {
        if (preg_match('/^Set-Cookie:\s*' . preg_quote($cookieName, '/') . '=([^;]*)/i', $l, $m) === 1) {
            $issued = $m[1] === 'deleted' ? null : $m[1];
        }
    }
    preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $mm);

    return ['status' => (int) ($mm[1] ?? 0), 'sid' => $issued];
}

function account(string $status = 'active'): array
{
    $password = bin2hex(random_bytes(16));
    $id = tk_user('analyst');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h, status = :s WHERE id = :id')
        ->execute(['h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 's' => $status, 'id' => $id]);

    return [$id, (string) tk_pdo()->query("SELECT username FROM users WHERE id = $id")->fetchColumn(), $password];
}

try {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $GLOBALS['base'] = "http://127.0.0.1:$port/index.php";
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', "session.save_path=$dir", '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }

    [$a, $aName, $aPassword] = account();
    [$b, $bName, $bPassword] = account('inactive');
    $unknown = tk_marker() . '_nobody_' . bin2hex(random_bytes(3));
    $secrets = [$aPassword, $bPassword, $aName, $bName, $unknown];

    /* Success: account, address, bounded and cleaned user agent -------------------------------------- */
    $longAgent = 'Mozilla/5.0 ' . str_repeat('x', 600) . "\x01\x7F" . "\xFF\xFE";
    $ok = req('POST', '/login', ['identifier' => $aName, 'password' => $aPassword], null, $longAgent);
    $success = audit_rows($start, AuthenticationAudit::LOGIN_SUCCESS);
    $row = $success[0] ?? [];
    check('success: one auth.login.success row with the account, the address and success = 1', $ok['status'] === 200 && count($success) === 1
        && (int) $row['user_id'] === $a && $row['ip_address'] === '127.0.0.1' && (int) $row['success'] === 1 && $row['details'] === null
        && $row['resource_type'] === null && $row['resource_id'] === null);
    check('success: user agent bounded to 255 characters, valid UTF-8, no control characters', mb_strlen((string) $row['user_agent']) <= 255
        && mb_check_encoding((string) $row['user_agent'], 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', (string) $row['user_agent']) === 0
        && str_starts_with((string) $row['user_agent'], 'Mozilla/5.0 '));

    /* Failures: identical rows, no account, no identifier ------------------------------------------- */
    $f1 = req('POST', '/login', ['identifier' => $aName, 'password' => 'wrong-' . bin2hex(random_bytes(4))]);
    $f2 = req('POST', '/login', ['identifier' => $unknown, 'password' => 'wrong-' . bin2hex(random_bytes(4))]);
    $f3 = req('POST', '/login', ['identifier' => $bName, 'password' => $bPassword]);
    $failures = audit_rows($start, AuthenticationAudit::LOGIN_FAILURE);
    $shape = array_map(static function (array $r): array {
        unset($r['id']);

        return $r;
    }, $failures);
    check('failures (wrong password, unknown identifier, inactive + correct password): 3 × 401, 3 rows', [$f1['status'], $f2['status'], $f3['status']] === [401, 401, 401]
        && count($failures) === 3);
    check('failure rows are identical: user_id NULL, success = 0, no details (nothing tells the cases apart)', count(array_unique(array_map('serialize', $shape))) === 1
        && $shape[0]['user_id'] === null && (int) $shape[0]['success'] === 0 && $shape[0]['details'] === null);

    /* Throttling: bounded ------------------------------------------------------------------------------ */
    $burst = tk_marker() . '_burst';
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = req('POST', '/login', ['identifier' => $burst, 'password' => 'w' . $i])['status'];
    }
    check('throttling: 5 × 401 then 3 × 429 → 5 more failure rows and a single auth.login.throttled row', $codes === [401, 401, 401, 401, 401, 429, 429, 429]
        && count(audit_rows($start, AuthenticationAudit::LOGIN_FAILURE)) === 8 && count(audit_rows($start, AuthenticationAudit::LOGIN_THROTTLED)) === 1);

    /* Sign-out ------------------------------------------------------------------------------------------ */
    $out = req('POST', '/logout', null, $ok['sid']);
    req('POST', '/logout');
    req('POST', '/logout', null, 'tk' . str_repeat('0', 32));
    $logouts = audit_rows($start, AuthenticationAudit::LOGOUT);
    check('sign-out with a session: one auth.logout row with the account; anonymous or unknown sessions: none', $out['status'] === 200
        && count($logouts) === 1 && (int) $logouts[0]['user_id'] === $a && (int) $logouts[0]['success'] === 1);

    /* Revocation ---------------------------------------------------------------------------------------- */
    $again = req('POST', '/login', ['identifier' => $aName, 'password' => $aPassword]);
    tk_pdo()->prepare("UPDATE users SET status = 'inactive' WHERE id = :id")->execute(['id' => $a]);
    $revoked = req('GET', '/api/metrics', null, $again['sid']);
    [$c, $cName, $cPassword] = account();
    $cSession = req('POST', '/login', ['identifier' => $cName, 'password' => $cPassword]);
    tk_pdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $c]);
    $gone = req('GET', '/api/metrics', null, $cSession['sid']);
    $revocations = audit_rows($start, AuthenticationAudit::SESSION_REVOKED);
    check('revocation (account made inactive): 401 and an auth.session.revoked row with the account', $revoked['status'] === 401
        && (int) ($revocations[0]['user_id'] ?? 0) === $a && (int) ($revocations[0]['success'] ?? 1) === 0);
    check('revocation (account deleted): 401, row still recorded with user_id NULL (no foreign-key error)', $gone['status'] === 401
        && count($revocations) === 2 && $revocations[1]['user_id'] === null);

    /* Injection in the user agent is stored as text ------------------------------------------------------ */
    $evil = "x'); DROP TABLE users; -- ";
    req('POST', '/login', ['identifier' => $unknown, 'password' => 'w'], null, $evil);
    $last = audit_rows($start, AuthenticationAudit::LOGIN_FAILURE);
    check('SQL metacharacters in the user agent are stored literally, users table intact', end($last)['user_agent'] === trim($evil)
        && (int) tk_pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() >= 1);

    /* Nothing sensitive anywhere in the stored rows ---------------------------------------------------- */
    $all = json_encode(audit_rows($start));
    $sids = array_filter([$ok['sid'], $again['sid'], $cSession['sid']]);
    $found = array_filter([...$secrets, ...$sids], static fn (string $s): bool => str_contains($all, $s));
    check('no password, identifier, session ID or password hash in any audit row', $found === [] && !preg_match('/\$2[aby]\$/', $all), (string) count($found));
    check('only the five authentication actions were written', array_diff(array_unique(array_column(audit_rows($start), 'action')),
        [AuthenticationAudit::LOGIN_SUCCESS, AuthenticationAudit::LOGIN_FAILURE, AuthenticationAudit::LOGIN_THROTTLED, AuthenticationAudit::LOGOUT, AuthenticationAudit::SESSION_REVOKED]) === []);

    /* In process: invalid address → NULL; audit failure never changes the outcome ----------------------- */
    $audit = new AuthenticationAudit(new AuditLogRepository(tk_pdo()));
    $audit->loginFailed(new HttpRequest('POST', '/login', [], [], true, 'not-an-address'));
    $lastRow = audit_rows($start);
    check('an invalid client address is stored as NULL', end($lastRow)['ip_address'] === null && end($lastRow)['action'] === AuthenticationAudit::LOGIN_FAILURE);
    $broken = tk_connect(TkThrowingPdo::class);
    $broken->failOn = 'audit_logs';
    $countBefore = count(audit_rows($start));
    $controller = new LoginController(new AuthenticationService(new UserRepository(tk_pdo())), new SessionManager(), null,
        new AuthenticationAudit(new AuditLogRepository($broken)));
    http_response_code(200);
    ob_start();
    $controller->login(new HttpRequest('POST', '/login', ['identifier' => $unknown, 'password' => 'w'], [], true, '198.51.100.61'));
    $body = json_decode((string) ob_get_clean(), true);
    check('audit storage failing: the failed sign-in still answers 401 "Invalid credentials.", nothing written', http_response_code() === 401
        && $body === ['success' => false, 'message' => 'Invalid credentials.'] && count(audit_rows($start)) === $countBefore);
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
