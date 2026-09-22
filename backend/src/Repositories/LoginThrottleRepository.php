<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use Closure;
use PDO;
use PDOException;

/**
 * Storage of the sign-in throttling buckets (APP-07.4.1, table login_throttle).
 * Holds no policy: LoginThrottle decides, this class reads and writes rows.
 */
final class LoginThrottleRepository
{
    private const DEADLOCK_RETRIES = 3;

    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * Bucket keys for an identifier (already trimmed) and a client address. Computed from the
     * identifier's utf8mb4_unicode_ci weights, the comparison used to find users, so every
     * variant that reaches the same account (case, accents, width) lands in the same bucket.
     *
     * @return array{account: string, source_account: string}
     */
    public function keys(#[\SensitiveParameter] string $identifier, string $source): array
    {
        $sql = <<<'SQL'
            SELECT
                SHA2(CONCAT('account', 0x00,
                    HEX(WEIGHT_STRING(CONVERT(:identifier_a USING utf8mb4) COLLATE utf8mb4_unicode_ci))), 256) AS account,
                SHA2(CONCAT('source_account', 0x00, :source, 0x00,
                    HEX(WEIGHT_STRING(CONVERT(:identifier_b USING utf8mb4) COLLATE utf8mb4_unicode_ci))), 256) AS source_account
        SQL;

        $statement = $this->connection->prepare($sql);

        $statement->execute([
            'identifier_a' => $identifier,
            'identifier_b' => $identifier,
            'source' => $source,
        ]);

        $row = $statement->fetch();

        return [
            'account' => (string) $row['account'],
            'source_account' => (string) $row['source_account'],
        ];
    }

    /**
     * Creates the missing buckets, outside any transaction: concurrent creations of the same
     * key collapse into one row, and no shared lock is held when the rows are locked later.
     *
     * @param array<string, string> $keys scope => key
     */
    public function ensure(array $keys, string $now): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO login_throttle (throttle_key, scope, attempts, last_attempt_at)
             VALUES (:throttle_key, :scope, 0, :now)
             ON DUPLICATE KEY UPDATE throttle_key = throttle_key'
        );

        foreach ($keys as $scope => $key) {
            $statement->execute([
                'throttle_key' => $key,
                'scope' => $scope,
                'now' => $now,
            ]);
        }
    }

    /**
     * Locks the buckets (always in key order, so concurrent sign-ins cannot deadlock on them).
     * Must run inside transaction().
     *
     * @param list<string> $keys
     * @return array<string, array{attempts: int, last_attempt_at: string, blocked_until: ?string}> key => row
     */
    public function lock(array $keys): array
    {
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $statement = $this->connection->prepare(
            "SELECT throttle_key, attempts, last_attempt_at, blocked_until
             FROM login_throttle
             WHERE throttle_key IN ($placeholders)
             ORDER BY throttle_key
             FOR UPDATE"
        );

        $statement->execute(array_values($keys));

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['throttle_key']] = [
                'attempts' => (int) $row['attempts'],
                'last_attempt_at' => (string) $row['last_attempt_at'],
                'blocked_until' => $row['blocked_until'] !== null ? (string) $row['blocked_until'] : null,
            ];
        }

        return $rows;
    }

    public function save(
        string $key,
        string $scope,
        int $attempts,
        string $lastAttemptAt,
        ?string $blockedUntil,
    ): void {
        $this->connection->prepare(
            'INSERT INTO login_throttle (throttle_key, scope, attempts, last_attempt_at, blocked_until)
             VALUES (:throttle_key, :scope, :attempts, :last_attempt_at, :blocked_until)
             ON DUPLICATE KEY UPDATE
                attempts = VALUES(attempts),
                last_attempt_at = VALUES(last_attempt_at),
                blocked_until = VALUES(blocked_until)'
        )->execute([
            'throttle_key' => $key,
            'scope' => $scope,
            'attempts' => $attempts,
            'last_attempt_at' => $lastAttemptAt,
            'blocked_until' => $blockedUntil,
        ]);
    }

    /** @param list<string> $keys */
    public function delete(array $keys): void
    {
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        $this->connection->prepare(
            "DELETE FROM login_throttle WHERE throttle_key IN ($placeholders)"
        )->execute(array_values($keys));
    }

    /** Removes buckets idle since before $cutoff (bounded batch, uses the last_attempt_at index). */
    public function purgeIdleSince(string $cutoff, int $limit = 100): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM login_throttle WHERE last_attempt_at < :cutoff LIMIT ' . max(1, $limit)
        );

        $statement->execute([
            'cutoff' => $cutoff,
        ]);
    }

    /**
     * Runs $work in a transaction; retried on deadlock or lock-wait timeout.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->connection->beginTransaction();

            try {
                $result = $work();
                $this->connection->commit();

                return $result;
            } catch (PDOException $exception) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                $retryable = in_array((string) $exception->getCode(), ['40001', 'HY000'], true)
                    && preg_match('/\b(1213|1205)\b/', $exception->getMessage()) === 1;

                if (!$retryable || $attempt >= self::DEADLOCK_RETRIES) {
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                if ($this->connection->inTransaction()) {
                    $this->connection->rollBack();
                }

                throw $exception;
            }
        }
    }
}
