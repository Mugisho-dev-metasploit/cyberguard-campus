<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use PDO;
use RuntimeException;

final class MetricsRepository
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function findSummary(): array
    {
        $sql = <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM events) AS total_events,
                (SELECT COUNT(*) FROM alerts) AS total_alerts,
                (SELECT COUNT(*) FROM alerts WHERE severity = 4) AS critical_alerts,
                (
                    SELECT COUNT(*)
                    FROM incidents
                    WHERE status IN ('open', 'acknowledged', 'investigating', 'contained')
                ) AS open_incidents,
                (SELECT COUNT(*) FROM devices) AS devices
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute();

        $row = $statement->fetch();

        if ($row === false) {
            throw new RuntimeException(
                'Unable to load metrics.'
            );
        }

        return [
            'total_events' => (int) $row['total_events'],
            'total_alerts' => (int) $row['total_alerts'],
            'critical_alerts' => (int) $row['critical_alerts'],
            'open_incidents' => (int) $row['open_incidents'],
            'devices' => (int) $row['devices'],
        ];
    }
}
