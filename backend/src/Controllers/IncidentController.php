<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Services\IncidentService;

final class IncidentController
{
    public function __construct(
        private readonly IncidentService $incidentService,
    ) {
    }

    public function index(HttpRequest $request): void
    {
        try {
            $incidents = $this->incidentService->listIncidents();
        } catch (\Throwable) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unable to retrieve incidents.',
                    'data' => [],
                ],
                500,
            );

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Incidents retrieved successfully.',
            'data' => $incidents,
        ]);
    }
}
