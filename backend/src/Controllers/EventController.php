<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Controllers;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use CyberGuard\Campus\Services\EventService;

final class EventController
{
    public function __construct(
        private readonly EventService $eventService,
    ) {
    }

    public function index(HttpRequest $request): void
    {
        try {
            $events = $this->eventService->listEvents();
        } catch (\Throwable) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Unable to retrieve events.',
                    'data' => [],
                ],
                500,
            );

            return;
        }

        HttpResponse::json([
            'success' => true,
            'message' => 'Events retrieved successfully.',
            'data' => $events,
        ]);
    }
}
