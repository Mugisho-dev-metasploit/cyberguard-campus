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

    /**
     * @param list<callable> $middleware
     */
    public function patch(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->add(
            method: 'PATCH',
            path: $path,
            handler: $handler,
            middleware: $middleware,
        );
    }

    public function dispatch(HttpRequest $request): void
    {
        $method = $request->method();
        $path = $request->path();

        $route = $this->matchRoute($method, $path);

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
        $request = $route['params'] === []
            ? $request
            : $request->withRouteParams($route['params']);

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
     * @return array{handler: callable, middleware: list<callable>, params: array<string, string>}|null
     */
    private function matchRoute(string $method, string $path): ?array
    {
        if (isset($this->routes[$method][$path])) {
            $route = $this->routes[$method][$path];

            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => [],
            ];
        }

        foreach ($this->routes[$method] ?? [] as $routePath => $route) {
            if ($routePath === $path) {
                continue;
            }

            $params = [];

            if ($this->pathMatchesPattern($routePath, $path, $params)) {
                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function pathMatchesPattern(
        string $routePath,
        string $requestPath,
        array &$params,
    ): bool {
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $routePath, $matches);

        $pattern = preg_quote($routePath, '#');
        $pattern = preg_replace(
            '#\\\{[a-zA-Z_][a-zA-Z0-9_]*\\\}#',
            '([^/]+)',
            $pattern,
        );

        if ($pattern === null) {
            return false;
        }

        $compiledPattern = '#^' . $pattern . '$#';

        if (!preg_match($compiledPattern, $requestPath, $routeMatches)) {
            return false;
        }

        $fieldNames = $matches[1] ?? [];

        foreach ($fieldNames as $index => $fieldName) {
            $params[$fieldName] = $routeMatches[$index + 1] ?? '';
        }

        return true;
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
