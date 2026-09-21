<?php

declare(strict_types=1);

/**
 * APP-06.8 — concurrency (APP-06.3 row lock), with two real OS processes.
 *
 * Worker A opens its own transaction, applies a transition through the service (which does not
 * commit a transaction it does not own), holds the row lock HOLD_SECONDS, then commits.
 * Worker B starts while A holds the lock and runs a normal service update. B must wait for the
 * lock, then decide on the state A committed. History must reflect only real transitions.
 *
 * Run: /opt/lampp/bin/php backend/tests/IncidentConcurrencyTest.php   (exit 0 = all passed)
 *   (workers: IncidentConcurrencyTest.php worker-a|worker-b <id> <status> <actor> <role>)
 */

use CyberGuard\Campus\Exceptions\AuthorizationException;
use CyberGuard\Campus\Repositories\IncidentRepository;
use CyberGuard\Campus\Services\IncidentService;

require __DIR__ . '/support/bootstrap.php';

const HOLD_SECONDS = 2;
const MIN_WAIT = 1.5;

/* ---- Workers (separate processes, separate connections) -------------------------------- */

if (in_array($argv[1] ?? '', ['worker-a', 'worker-b'], true)) {
    [$worker, $id, $status, $actor, $role] = [$argv[1], (int) $argv[2], $argv[3], (int) $argv[4], $argv[5]];
    $pdo = tk_connect();
    $service = new IncidentService(new IncidentRepository($pdo));

    if ($worker === 'worker-a') {
        $pdo->beginTransaction();
        $service->updateIncident($id, ['status' => $status], $actor, $role);
        fwrite(STDOUT, "A-LOCKED\n");
        fflush(STDOUT);
        sleep(HOLD_SECONDS);
        $pdo->commit();
        exit(0);
    }

    $start = microtime(true);
    try {
        $data = $service->updateIncident($id, ['status' => $status], $actor, $role);
        $outcome = "accepted {$data['status']}";
    } catch (InvalidArgumentException $e) {
        $outcome = 'refused422 ' . $e->getMessage();
    } catch (AuthorizationException) {
        $outcome = 'forbidden403';
    }
    fwrite(STDOUT, sprintf("B-DONE %.3f %s\n", microtime(true) - $start, $outcome));
    exit(0);
}

/* ---- Driver ----------------------------------------------------------------------------- */

function spawn(string $worker, int $id, string $status, int $actor, string $role): array
{
    $cmd = sprintf('%s %s %s %d %s %d %s', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), $worker, $id, escapeshellarg($status), $actor, escapeshellarg($role));
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    return [$process, $pipes];
}

function finish(array $worker): string
{
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $out;
}

/** A holds the lock; B starts during the hold. Returns [B's wait in seconds, B's outcome]. */
function race(int $id, array $a, array $b): array
{
    $workerA = spawn('worker-a', $id, ...$a);
    $locked = trim((string) fgets($workerA[1][1]));
    $workerB = spawn('worker-b', $id, ...$b);
    $outB = trim(finish($workerB));
    finish($workerA);

    if ($locked !== 'A-LOCKED' || !preg_match('/^B-DONE (\d+\.\d+) (.*)$/', $outB, $m)) {
        return [0.0, "unexpected: A=[$locked] B=[$outB]"];
    }

    return [(float) $m[1], $m[2]];
}

$before = tk_counts();

try {
    $admin = tk_user('admin');
    $analyst = tk_user('analyst');

    // Scenario A — A: open → acknowledged; B: open → closed (admin, so only the workflow can refuse)
    $id = tk_incident('open');
    [$wait, $outcome] = race($id, ['acknowledged', $analyst, 'analyst'], ['closed', $admin, 'admin']);
    $h = tk_history($id);
    check('A: B waited for the lock', $wait >= MIN_WAIT, "$wait s");
    check('A: B read acknowledged and was refused (422 from acknowledged to closed)', str_starts_with($outcome, 'refused422') && str_contains($outcome, 'from acknowledged to closed'), $outcome);
    check('A: final state acknowledged, 1 history row (A, by A\'s actor), acknowledged_at only',
        tk_row($id)['status'] === 'acknowledged' && count($h) === 1 && (int) $h[0]['user_id'] === $analyst && tk_stamped(tk_row($id)) === ['acknowledged_at']);

    // Scenario B — A: open → acknowledged; B: acknowledged → investigating
    $id = tk_incident('open');
    [$wait, $outcome] = race($id, ['acknowledged', $analyst, 'analyst'], ['investigating', $admin, 'admin']);
    $h = tk_history($id);
    check('B: B waited for the lock', $wait >= MIN_WAIT, "$wait s");
    check('B: B read acknowledged and succeeded', $outcome === 'accepted investigating', $outcome);
    check('B: 2 history rows in order, each with its own actor',
        count($h) === 2 && [$h[0]['new_status'], (int) $h[0]['user_id']] === ['acknowledged', $analyst]
        && [$h[1]['previous_status'], $h[1]['new_status'], (int) $h[1]['user_id']] === ['acknowledged', 'investigating', $admin]);

    // Scenario C — both: open → acknowledged
    $id = tk_incident('open');
    [$wait, $outcome] = race($id, ['acknowledged', $analyst, 'analyst'], ['acknowledged', $admin, 'admin']);
    check('C: B waited for the lock', $wait >= MIN_WAIT, "$wait s");
    check('C: the second request is a no-op (accepted, still acknowledged)', $outcome === 'accepted acknowledged', $outcome);
    check('C: one real transition, one history row', count(tk_history($id)) === 1 && tk_row($id)['status'] === 'acknowledged');

    // Role under contention — A (admin): contained → resolved; B (analyst): → closed
    $id = tk_incident('contained');
    [$wait, $outcome] = race($id, ['resolved', $admin, 'admin'], ['closed', $analyst, 'analyst']);
    check('role: analyst close waited, then 403 on the fresh resolved state', $wait >= MIN_WAIT && $outcome === 'forbidden403' && tk_row($id)['status'] === 'resolved', "$wait $outcome");
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, $e::class . ': ' . $e->getMessage());
} finally {
    tk_cleanup();
}

tk_finish($before);
