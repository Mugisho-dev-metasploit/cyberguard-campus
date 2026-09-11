<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Core;

final class HttpResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public static function json(
        array $data,
        int $statusCode = 200,
    ): void {
        http_response_code($statusCode);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    private function __construct()
    {
    }
}
