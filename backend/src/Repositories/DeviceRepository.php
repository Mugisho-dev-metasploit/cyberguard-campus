<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Repositories;

use CyberGuard\Campus\Models\Device;
use PDO;
use RuntimeException;

final class DeviceRepository
{
    public function __construct(
        private readonly PDO $connection,
    ) {
    }

    /**
     * @return list<Device>
     */
    public function findAll(): array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                uuid,
                hostname,
                ip_address,
                mac_address,
                device_type,
                vendor,
                operating_system,
                environment,
                status,
                last_seen_at,
                created_at,
                updated_at
            FROM devices
            ORDER BY last_seen_at DESC, id DESC
        SQL;

        $statement = $this->connection->prepare($sql);
        $statement->execute();

        $rows = $statement->fetchAll();

        if ($rows === false) {
            throw new RuntimeException(
                'Unable to load devices.'
            );
        }

        return array_map(
            fn (array $row): Device => $this->mapToDevice($row),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapToDevice(array $row): Device
    {
        return new Device(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            hostname: (string) $row['hostname'],
            ipAddress: $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            macAddress: $row['mac_address'] !== null ? (string) $row['mac_address'] : null,
            deviceType: (string) $row['device_type'],
            vendor: $row['vendor'] !== null ? (string) $row['vendor'] : null,
            operatingSystem: $row['operating_system'] !== null ? (string) $row['operating_system'] : null,
            environment: (string) $row['environment'],
            status: (string) $row['status'],
            lastSeenAt: $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
