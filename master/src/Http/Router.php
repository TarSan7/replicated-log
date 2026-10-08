<?php
declare(strict_types=1);

namespace Master\Http;

use Master\Logger;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class Router
{
    /** @var array<string, callable(Request): JsonResponse> */
    private array $routes = [];

    public function __construct(private readonly Logger $logger)
    {
    }

    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): self
    {
        $this->routes[strtoupper($method) . ' ' . $path] = $handler;
        return $this;
    }

    public function dispatch(Request $request, Response $response): void
    {
        $start   = hrtime(true);
        $key     = $request->server['request_method'] . ' ' . $request->server['request_uri'];
        $handler = $this->routes[$key] ?? null;

        try {
            $result = $handler !== null
                ? $handler($request)
                : new JsonResponse(['error' => 'not found'], 404);
        } catch (\Throwable $e) {
            $this->logger->error('unhandled exception', ['route' => $key, 'error' => $e->getMessage()]);
            $result = new JsonResponse(['error' => 'internal server error'], 500);
        }

        Json::send($response, $result);

        $this->logger->info($key, [
            'status' => $result->status,
            'ms'     => round((hrtime(true) - $start) / 1e6, 1),
            'client' => $request->server['remote_addr'] ?? null,
        ]);
    }
}
