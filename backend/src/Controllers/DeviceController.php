<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Services\DeviceService;

final class DeviceController
{
    public function __construct(
        private readonly DeviceService $deviceService,
    ) {
    }

    public function index(HttpRequest $request): void
    {
        try {
            $devices = $this->deviceService->listDevices();
        } catch (\Throwable) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unable to retrieve devices.',
                    'data' => [],
                ],
                500,
            );

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Devices retrieved successfully.',
            'data' => $devices,
        ]);
    }
}
