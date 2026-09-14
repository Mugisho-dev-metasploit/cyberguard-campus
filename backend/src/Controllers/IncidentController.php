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

    public function update(HttpRequest $request): void
    {
        $incidentId = $request->routeParam('id');

        if ($incidentId === null || !ctype_digit($incidentId)) {
            $this->error('Invalid incident identifier.', 400);

            return;
        }

        if (!$request->jsonValid()) {
            $this->error('Invalid JSON payload.', 400);

            return;
        }

        try {
            $updatedIncident = $this->incidentService->updateIncident((int) $incidentId, $request->body());
        } catch (\InvalidArgumentException $exception) {
            $statusCode = $exception->getCode();
            $this->error(
                $exception->getMessage(),
                is_int($statusCode) && $statusCode >= 400 && $statusCode < 600
                    ? $statusCode
                    : 422,
            );

            return;
        } catch (\RuntimeException $exception) {
            $statusCode = $exception->getCode();
            $this->error(
                $exception->getMessage(),
                is_int($statusCode) && $statusCode >= 400 && $statusCode < 600
                    ? $statusCode
                    : 500,
            );

            return;
        } catch (\Throwable) {
            $this->error('Unable to update incident.', 500);

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Incident updated successfully.',
            'data' => $updatedIncident,
        ]);
    }

    private function error(string $message, int $statusCode): void
    {
        HttpResponse::json(
            [
                'success' => false,
                'message' => $message,
            ],
            $statusCode,
        );
    }
}
