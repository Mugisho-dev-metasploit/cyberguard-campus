<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Core\SessionManager;
use CyberGuard\Campus\Exceptions\AuthorizationException;
use CyberGuard\Campus\Services\IncidentService;

final class IncidentController
{
    public function __construct(
        private readonly IncidentService $incidentService,
        private readonly SessionManager $sessionManager,
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

    /** GET /api/incidents/{id} — any authenticated role (enforced on the route). */
    public function show(HttpRequest $request): void
    {
        $incidentId = $request->routeParam('id');

        if ($incidentId === null || !ctype_digit($incidentId)) {
            $this->error('Invalid incident identifier.', 400);

            return;
        }

        try {
            $detail = $this->incidentService->getIncidentDetail((int) $incidentId);
        } catch (\Throwable $exception) {
            // Same rule as update(): only "not found" is a client error; nothing internal leaks.
            if (
                $exception instanceof \RuntimeException
                && !$exception instanceof \PDOException
                && $exception->getCode() === 404
            ) {
                $this->error('Incident not found.', 404);

                return;
            }

            $this->error('Unable to retrieve incident.', 500);

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Incident retrieved successfully.',
            'data' => $detail,
        ]);
    }

    public function update(HttpRequest $request): void
    {
        // Who is acting, and with which role: from the authenticated session only, never from
        // the payload. (The route middleware already answered 401/403 for no session / viewer.)
        $actorId = $this->sessionManager->userId();
        $actorRole = $this->sessionManager->role();

        if ($actorId === null || $actorRole === null) {
            $this->error('Authentication required.', 401);

            return;
        }

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
            $updatedIncident = $this->incidentService->updateIncident((int) $incidentId, $request->body(), $actorId, $actorRole);
        } catch (\InvalidArgumentException $exception) {
            // Validation and workflow errors: messages written by IncidentService for the client.
            $this->error($exception->getMessage(), 422);

            return;
        } catch (AuthorizationException) {
            $this->error('Insufficient permissions.', 403);

            return;
        } catch (\Throwable $exception) {
            // Everything else is internal (database, reload, unexpected state): the transaction
            // is already rolled back and no internal detail leaves the server. PDOException and
            // other RuntimeExceptions land here too; only "incident not found" is a client error.
            if (
                $exception instanceof \RuntimeException
                && !$exception instanceof \PDOException
                && $exception->getCode() === 404
            ) {
                $this->error('Incident not found.', 404);

                return;
            }

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
