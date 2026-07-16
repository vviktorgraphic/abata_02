<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        $handler = $this->routes[strtoupper($method)][$path] ?? null;
        $parameters = [];
        if ($handler === null) {
            foreach ($this->routes[strtoupper($method)] ?? [] as $route => $candidate) {
                $pattern = preg_replace_callback('/\\{([A-Za-z_][A-Za-z0-9_]*)\\}/', static function (array $match): string {
                    return '(?P<' . $match[1] . '>[^/]+)';
                }, $route);
                if ($pattern !== null && preg_match('#^' . $pattern . '$#D', $path, $matches) === 1) {
                    $handler = $candidate;
                    foreach ($matches as $name => $value) {
                        if (is_string($name)) {
                            $parameters[$name] = rawurldecode($value);
                        }
                    }
                    break;
                }
            }
        }

        if ($handler === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Not found'], JSON_THROW_ON_ERROR);
            return;
        }

        $handler($_GET, $parameters);
    }
}
