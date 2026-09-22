<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use PDO;

/**
 * Writes to audit_logs (APP074-11). Values arrive already validated and bounded by the caller;
 * every statement is prepared.
 */
final class AuditLogRepository
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * @param array<string, int|string|bool|null>|null $details
     */
    public function record(
        string $action,
        ?int $userId,
        bool $success,
        ?string $ipAddress,
        ?string $userAgent,
        ?array $details = null,
    ): void {
        // user_id through a sub-query: an account deleted meanwhile gives NULL instead of a
        // foreign-key error, so the event is still recorded.
        $sql = <<<'SQL'
            INSERT INTO audit_logs (user_id, action, ip_address, user_agent, success, details)
            VALUES ((SELECT id FROM users WHERE id = :user_id), :action, :ip_address, :user_agent, :success, :details)
        SQL;

        $this->connection->prepare($sql)->execute([
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'success' => $success ? 1 : 0,
            'details' => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    /** True when $action was recorded for $ipAddress during the last $seconds (database clock). */
    public function recordedRecently(string $action, string $ipAddress, int $seconds): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM audit_logs
             WHERE ip_address = :ip_address AND action = :action AND created_at >= NOW(6) - INTERVAL ' . max(1, $seconds) . ' SECOND
             LIMIT 1'
        );

        $statement->execute([
            'ip_address' => $ipAddress,
            'action' => $action,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
