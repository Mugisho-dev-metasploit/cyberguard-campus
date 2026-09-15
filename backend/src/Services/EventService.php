<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Models\Event;
use CyberGuard\Campus\Repositories\EventRepository;

final class EventService
{
    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEvents(): array
    {
        $events = $this->eventRepository->findAll();

        return array_map(
            static fn (Event $event): array => $event->toArray(),
            $events,
        );
    }
}
