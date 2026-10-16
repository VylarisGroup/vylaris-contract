<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler Router. Routen: Methode + Pfadmuster mit {param}-Platzhaltern.
 * Parameter sind ausschliesslich Ziffern oder [A-Za-z0-9_-]; keine Pfadtraversierung möglich.
 * Middleware wird pro Route als Liste aufgerufen (Reihenfolge = Ausführungsreihenfolge).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable,middleware:list<callable>}> */
    private array $routes = [];

    /** @param list<callable> $middleware */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $regex = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @param list<callable> $middleware */
    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler = $route['handler'];
            $next = static fn (Request $r): Response => $handler($r, $params);
            foreach (array_reverse($route['middleware']) as $mw) {
                $inner = $next;
                $next = static fn (Request $r): Response => $mw($r, $inner, $params);
            }
            return $next($request);
        }
        return $pathMatched
            ? Response::html('Methode nicht erlaubt.', 405)
            : Response::html('Seite nicht gefunden.', 404);
    }
}
