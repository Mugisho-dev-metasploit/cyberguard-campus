<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = self::requiredEnv('DB_HOST');
        $port = self::requiredEnv('DB_PORT');
        $database = self::requiredEnv('DB_DATABASE');
        $username = self::requiredEnv('DB_USERNAME');
        $password = self::requiredEnv('DB_PASSWORD');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Database connection failed.',
                0,
                $exception
            );
        }

        return self::$connection;
    }

    private static function requiredEnv(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null || trim($value) === '') {
            throw new RuntimeException(
                sprintf('Missing required environment variable: %s', $key)
            );
        }

        return $value;
    }

    private function __construct()
    {
    }
}
