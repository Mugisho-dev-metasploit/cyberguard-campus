<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Core;

final class HttpRequest
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH
        );

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            $body = $_POST;
        } else {
            $decoded = json_decode(
                $rawBody,
                true
            );

            $body = is_array($decoded)
                ? $decoded
                : $_POST;
        }

        return new self(
            method: $method,
            path: $path,
            body: $body,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }
}
