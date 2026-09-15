<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Models\Device;
use CyberGuard\Campus\Repositories\DeviceRepository;

final class DeviceService
{
    public function __construct(
        private readonly DeviceRepository $deviceRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDevices(): array
    {
        $devices = $this->deviceRepository->findAll();

        return array_map(
            static fn (Device $device): array => $device->toArray(),
            $devices,
        );
    }
}
