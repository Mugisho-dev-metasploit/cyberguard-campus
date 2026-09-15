<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use CyberGuard\Campus\Models\Alert;
use PDO;
use RuntimeException;

final class AlertRepository
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * @return list<Alert>
     */
    public function findAll(): array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                alert_uuid,
                event_id,
                device_id,
                source,
                alert_type,
                severity,
                title,
                description,
                signature,
                category,
                status,
                detected_at,
                acknowledged_at,
                resolved_at,
                assigned_to,
                created_at,
                updated_at
            FROM alerts
            ORDER BY detected_at DESC, id DESC
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute();

        $rows = $statement->fetchAll();

        if ($rows === false) {
            throw new RuntimeException(
                'Unable to load alerts.'
            );
        }

        return array_map(
            fn (array $row): Alert => $this->mapToAlert($row),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToAlert(array $row): Alert
    {
        return new Alert(
            id: (int) $row['id'],
            alertUuid: (string) $row['alert_uuid'],
            eventId: $row['event_id'] !== null ? (int) $row['event_id'] : null,
            deviceId: $row['device_id'] !== null ? (int) $row['device_id'] : null,
            source: (string) $row['source'],
            alertType: (string) $row['alert_type'],
            severity: (int) $row['severity'],
            title: (string) $row['title'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            signature: $row['signature'] !== null ? (string) $row['signature'] : null,
            category: $row['category'] !== null ? (string) $row['category'] : null,
            status: (string) $row['status'],
            detectedAt: (string) $row['detected_at'],
            acknowledgedAt: $row['acknowledged_at'] !== null ? (string) $row['acknowledged_at'] : null,
            resolvedAt: $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            assignedTo: $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
