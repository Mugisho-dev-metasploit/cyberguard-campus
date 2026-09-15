<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Models\Alert;
use CyberGuard\Campus\Repositories\AlertRepository;

final class AlertService
{
    public function __construct(
        private readonly AlertRepository $alertRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAlerts(): array
    {
        $alerts = $this->alertRepository->findAll();

        return array_map(
            static fn (Alert $alert): array => $alert->toArray(),
            $alerts,
        );
    }
}
