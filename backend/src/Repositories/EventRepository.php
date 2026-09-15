<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use CyberGuard\Campus\Models\Event;
use PDO;
use RuntimeException;

final class EventRepository
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * @return list<Event>
     */
    public function findAll(): array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                event_uuid,
                device_id,
                source,
                event_type,
                severity,
                event_timestamp,
                src_ip,
                src_port,
                dst_ip,
                dst_port,
                protocol,
                signature,
                category,
                processed_at,
                created_at
            FROM events
            ORDER BY event_timestamp DESC, id DESC
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute();

        $rows = $statement->fetchAll();

        if ($rows === false) {
            throw new RuntimeException(
                'Unable to load events.'
            );
        }

        return array_map(
            fn (array $row): Event => $this->mapToEvent($row),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToEvent(array $row): Event
    {
        return new Event(
            id: (int) $row['id'],
            eventUuid: (string) $row['event_uuid'],
            deviceId: $row['device_id'] !== null ? (int) $row['device_id'] : null,
            source: (string) $row['source'],
            eventType: (string) $row['event_type'],
            severity: (int) $row['severity'],
            eventTimestamp: (string) $row['event_timestamp'],
            srcIp: $row['src_ip'] !== null ? (string) $row['src_ip'] : null,
            srcPort: $row['src_port'] !== null ? (int) $row['src_port'] : null,
            dstIp: $row['dst_ip'] !== null ? (string) $row['dst_ip'] : null,
            dstPort: $row['dst_port'] !== null ? (int) $row['dst_port'] : null,
            protocol: $row['protocol'] !== null ? (string) $row['protocol'] : null,
            signature: $row['signature'] !== null ? (string) $row['signature'] : null,
            category: $row['category'] !== null ? (string) $row['category'] : null,
            processedAt: $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
            createdAt: (string) $row['created_at'],
        );
    }
}
