<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Models;

final class Device
{
    public function __construct(
        private readonly int $id,
        private readonly string $uuid,
        private readonly string $hostname,
        private readonly ?string $ipAddress,
        private readonly ?string $macAddress,
        private readonly string $deviceType,
        private readonly ?string $vendor,
        private readonly ?string $operatingSystem,
        private readonly string $environment,
        private readonly string $status,
        private readonly ?string $lastSeenAt,
        private readonly string $createdAt,
        private readonly string $updatedAt,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function macAddress(): ?string
    {
        return $this->macAddress;
    }

    public function deviceType(): string
    {
        return $this->deviceType;
    }

    public function vendor(): ?string
    {
        return $this->vendor;
    }

    public function operatingSystem(): ?string
    {
        return $this->operatingSystem;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lastSeenAt(): ?string
    {
        return $this->lastSeenAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'hostname' => $this->hostname,
            'ip_address' => $this->ipAddress,
            'mac_address' => $this->macAddress,
            'device_type' => $this->deviceType,
            'vendor' => $this->vendor,
            'operating_system' => $this->operatingSystem,
            'environment' => $this->environment,
            'status' => $this->status,
            'last_seen_at' => $this->lastSeenAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
