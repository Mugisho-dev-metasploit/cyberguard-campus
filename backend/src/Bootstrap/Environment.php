<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Bootstrap;

use Dotenv\Dotenv;
use RuntimeException;

final class Environment
{
    private static bool $loaded = false;

    public static function load(string $projectRoot): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_dir($projectRoot)) {
            throw new RuntimeException(
                sprintf('Invalid project root: %s', $projectRoot)
            );
        }

        $envFile = $projectRoot . '/.env';

        if (!is_file($envFile)) {
            throw new RuntimeException(
                'Environment file .env not found.'
            );
        }

        Dotenv::createImmutable($projectRoot)->load();

        self::$loaded = true;
    }

    private function __construct()
    {
    }
}
