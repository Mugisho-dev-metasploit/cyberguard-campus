<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Services\MetricsService;

final class MetricsController
{
    public function __construct(
        private readonly MetricsService $metricsService,
    ) {
    }

    public function index(HttpRequest $request): void
    {
        try {
            $metrics = $this->metricsService->getMetrics();
        } catch (\Throwable) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unable to retrieve metrics.',
                    'data' => [],
                ],
                500,
            );

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Metrics retrieved successfully.',
            'data' => $metrics,
        ]);
    }
}
