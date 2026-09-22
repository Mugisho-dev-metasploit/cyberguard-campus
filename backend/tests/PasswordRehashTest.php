<?php

declare(strict_types=1);

/**
 * APP074-10 — a password hash weaker than the policy (bcrypt, cost 12) is upgraded on the next
 * successful sign-in, never on a failed one, never downgraded, and a failed upgrade never blocks
 * the sign-in. APP074-19 — once the session is established, a failure to reset the throttle
 * buckets no longer turns the sign-in into an error.
 *
 * In process, on the configured database: the real AuthenticationService, UserRepository,
 * LoginController, LoginThrottle and SessionManager (private session store), with TkThrowingPdo
 * to make one statement fail. Temporary users with random passwords; everything is removed.
 *
 * Run: /opt/lampp/bin/php backend/tests/PasswordRehashTest.php   (exit 0 = all passed)
 */

use CyberGuard\Campus\Controllers\LoginController;
use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use CyberGuard\Campus\Repositories\UserRepository;
use CyberGuard\Campus\Services\AuthenticationService;
use CyberGuard\Campus\Services\LoginThrottle;

require __DIR__ . '/support/bootstrap.php';

// The controller is called in process: keep output buffered so it can send headers.
ob_start();
$dir = sys_get_temp_dir() . '/cg-rehash-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
ini_set('session.save_path', $dir);
ini_set('display_errors', '0');
$_ENV['SESSION_NAME'] = 'cg_rehash_test';
$before = tk_counts();

/** A temporary account with a hash made with the given cost (random password). */
function account(int $cost, string $status = 'active'): array
{
    $password = bin2hex(random_bytes(12));
    $id = tk_user('viewer');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h, status = :s WHERE id = :id')
        ->execute(['h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]), 's' => $status, 'id' => $id]);

    return [$id, (string) tk_pdo()->query("SELECT username FROM users WHERE id = $id")->fetchColumn(), $password];
}

/** Test double: runs a concurrent change right before the rehash UPDATE is prepared. */
final class TkRacingPdo extends PDO
{
    public ?Closure $beforeRehash = null;
    public bool $fired = false;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (!$this->fired && $this->beforeRehash !== null && str_contains($query, 'SET password_hash')) {
            $this->fired = true;
            ($this->beforeRehash)();
        }

        return parent::prepare($query, $options);
    }
}

function stored_hash(int $id): string
{
    return (string) tk_pdo()->query("SELECT password_hash FROM users WHERE id = $id")->fetchColumn();
}

function cost(string $hash): int
{
    return (int) (password_get_info($hash)['options']['cost'] ?? 0);
}

try {
    $service = new AuthenticationService(new UserRepository(tk_pdo()));

    /* Policy -------------------------------------------------------------------------------------- */
    $reference = (new ReflectionClassConstant(AuthenticationService::class, 'REFERENCE_HASH'))->getValue();
    check('policy: bcrypt cost 12, same cost as REFERENCE_HASH, above PASSWORD_DEFAULT', AuthenticationService::PASSWORD_ALGORITHM === PASSWORD_BCRYPT
        && AuthenticationService::PASSWORD_OPTIONS === ['cost' => 12] && cost($reference) === 12
        && cost(password_hash('x', PASSWORD_DEFAULT)) <= 12);

    /* Weaker hash: upgraded on success only --------------------------------------------------------- */
    [$weak, $weakName, $weakPassword] = account(10);
    $old = stored_hash($weak);
    $failed = $service->authenticate($weakName, 'wrong-' . bin2hex(random_bytes(4)))->isAuthenticated();
    check('failed sign-in leaves a cost-10 hash untouched', $failed === false && stored_hash($weak) === $old);
    $ok = $service->authenticate($weakName, $weakPassword)->isAuthenticated();
    $new = stored_hash($weak);
    check('successful sign-in upgrades a cost-10 hash to bcrypt cost 12', $ok && $new !== $old && cost($new) === 12
        && password_get_info($new)['algoName'] === 'bcrypt');
    check('the upgraded hash still verifies the same password, and signing in again keeps it', password_verify($weakPassword, $new)
        && $service->authenticate($weakName, $weakPassword)->isAuthenticated() && stored_hash($weak) === $new);

    /* Policy-strength hash: never rewritten; stronger hash: never downgraded ---------------------------- */
    [$current, $currentName, $currentPassword] = account(12);
    $h12 = stored_hash($current);
    [$strong, $strongName, $strongPassword] = account(13);
    $h13 = stored_hash($strong);
    check('cost-12 hash unchanged after sign-in', $service->authenticate($currentName, $currentPassword)->isAuthenticated() && stored_hash($current) === $h12);
    check('cost-13 hash never downgraded: unchanged after sign-in', $service->authenticate($strongName, $strongPassword)->isAuthenticated()
        && stored_hash($strong) === $h13 && cost($h13) === 13);

    /* Refused account: no rehash ------------------------------------------------------------------------ */
    [$inactive, $inactiveName, $inactivePassword] = account(10, 'inactive');
    $hi = stored_hash($inactive);
    check('inactive account with the correct password: refused and hash untouched', $service->authenticate($inactiveName, $inactivePassword)->isAuthenticated() === false
        && stored_hash($inactive) === $hi);

    /* Other algorithm (crypt SHA-512, verifiable by password_verify) → upgraded to bcrypt cost 12 ------ */
    [$legacy, $legacyName, $legacyPassword] = account(12);
    $sha512 = crypt($legacyPassword, '$6$rounds=5000$' . bin2hex(random_bytes(6)) . '$');
    tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => $sha512, 'id' => $legacy]);
    $legacyOk = $service->authenticate($legacyName, $legacyPassword)->isAuthenticated();
    $upgraded = stored_hash($legacy);
    check('other algorithm (crypt SHA-512): sign-in succeeds and the hash becomes bcrypt cost 12', $legacyOk && str_starts_with($upgraded, '$2y$12$')
        && password_verify($legacyPassword, $upgraded));

    /* Concurrent password change between verification and rehash: never overwritten ------------------ */
    [$raced, $racedName, $racedPassword] = account(10);
    $adminHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT, ['cost' => 12]);   // new password set meanwhile
    $racing = tk_connect(TkRacingPdo::class);
    $racing->beforeRehash = static function () use ($raced, $adminHash): void {
        tk_pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => $adminHash, 'id' => $raced]);
    };
    $racedOk = (new AuthenticationService(new UserRepository($racing)))->authenticate($racedName, $racedPassword)->isAuthenticated();
    check('password changed between verification and rehash: the new hash is kept, not reverted to the old password', $racedOk
        && stored_hash($raced) === $adminHash && $racing->fired);
    check('repository: a stale current hash replaces nothing', (new UserRepository(tk_pdo()))->updatePasswordHash($raced, $sha512, $sha512) === false
        && stored_hash($raced) === $adminHash);

    /* Rehash failure never blocks the sign-in ---------------------------------------------------------- */
    [$fragile, $fragileName, $fragilePassword] = account(10);
    $hf = stored_hash($fragile);
    $throwing = tk_connect(TkThrowingPdo::class);
    $throwing->failOn = 'SET password_hash';
    $result = (new AuthenticationService(new UserRepository($throwing)))->authenticate($fragileName, $fragilePassword);
    check('rehash failure (database error): sign-in still succeeds, old hash kept', $result->isAuthenticated() && stored_hash($fragile) === $hf);

    /* APP074-19 — throttle reset failure after an established session ------------------------------- */
    [$user, $userName, $userPassword] = account(12);
    $throttlePdo = tk_connect(TkThrowingPdo::class);
    $throttlePdo->failOn = 'DELETE FROM login_throttle WHERE throttle_key IN';
    $controller = new LoginController(new AuthenticationService(new UserRepository(tk_pdo())), new SessionManager(),
        new LoginThrottle(new LoginThrottleRepository($throttlePdo)));
    http_response_code(200);
    ob_start();
    $controller->login(new HttpRequest('POST', '/login', ['identifier' => $userName, 'password' => $userPassword], [], true, '198.51.100.91'));
    $body = json_decode((string) ob_get_clean(), true);
    $established = session_status() === PHP_SESSION_ACTIVE && ($_SESSION['user_id'] ?? null) === $user && ($_SESSION['authenticated'] ?? null) === true;
    check('APP074-19: throttle reset fails after the session is established → still 200 success with the session', http_response_code() === 200
        && ($body['success'] ?? null) === true && $established);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    /* Nothing sensitive reaches a response ------------------------------------------------------------ */
    check('no hash in the sign-in response', !str_contains(json_encode($body), '$2y$'));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    tk_cleanup();
}

tk_finish($before);
