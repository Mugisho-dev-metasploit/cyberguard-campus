<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Models\Incident;
use CyberGuard\Campus\Repositories\IncidentRepository;

final class IncidentService
{
    public function __construct(
        private readonly IncidentRepository $incidentRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIncidents(): array
    {
        $incidents = $this->incidentRepository->findAll();

        return array_map(
            static fn (Incident $incident): array => $incident->toArray(),
            $incidents,
        );
    }
}
