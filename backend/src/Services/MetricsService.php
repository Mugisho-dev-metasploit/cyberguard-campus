<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use CyberGuard\Campus\Repositories\MetricsRepository;

final class MetricsService
{
    public function __construct(
        private readonly MetricsRepository $metricsRepository,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function getMetrics(): array
    {
        return $this->metricsRepository->findSummary();
    }
}
