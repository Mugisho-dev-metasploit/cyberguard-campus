<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Routing;

use CyberGuard\Campus\Core\HttpRequest;
use CyberGuard\Campus\Core\HttpResponse;
use RuntimeException;

final class Router
{
    /**
     * @var array<string, array<string, array{
     *     handler: callable,
     *     middleware: list<callable>
     * }>>
     */
    private array $routes = [];

    /**
     * @param list<callable> $middleware
     */
    public function get(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->add(
            method: 'GET',
            path: $path,
            handler: $handler,
            middleware: $middleware,
        );
    }

    /**
     * @param list<callable> $middleware
     */
    public function post(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->add(
            method: 'POST',
            path: $path,
            handler: $handler,
            middleware: $middleware,
        );
    }

    public function dispatch(HttpRequest $request): void
    {
        $method = $request->method();
        $path = $request->path();

        $route = $this->routes[$method][$path] ?? null;

        if ($route === null) {
            HttpResponse::json(
                [
                    'success' => false,
                    'message' => 'Route not found.',
                ],
                404
            );

            return;
        }

        $handler = $route['handler'];
        $middleware = $route['middleware'];

        $pipeline = array_reduce(
            array_reverse($middleware),
            static function (
                callable $next,
                callable $middleware,
            ): callable {
                return static function (
                    HttpRequest $request,
                ) use (
                    $middleware,
                    $next,
                ): void {
                    $middleware(
                        $request,
                        $next,
                    );
                };
            },
            static function (
                HttpRequest $request,
            ) use ($handler): void {
                $handler($request);
            }
        );

        $pipeline($request);
    }

    /**
     * @param list<callable> $middleware
     */
    private function add(
        string $method,
        string $path,
        callable $handler,
        array $middleware,
    ): void {
        $method = strtoupper($method);

        if ($path === '') {
            throw new RuntimeException(
                'Route path cannot be empty.'
            );
        }

        $this->routes[$method][$path] = [
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }
}
