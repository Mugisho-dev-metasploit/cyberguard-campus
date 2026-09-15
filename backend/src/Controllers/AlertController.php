<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Services\AlertService;

final class AlertController
{
    public function __construct(
        private readonly AlertService $alertService,
    ) {
    }

    public function index(HttpRequest $request): void
    {
        try {
            $alerts = $this->alertService->listAlerts();
        } catch (\Throwable) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unable to retrieve alerts.',
                    'data' => [],
                ],
                500,
            );

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Alerts retrieved successfully.',
            'data' => $alerts,
        ]);
    }
}
