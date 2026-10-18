<?php

declare(strict_types=1);

namespace Emc\Core;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable}> */
    private array $routen = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}#', '(?P<$1>[^/]+)', preg_quote($this->normalisiere($pattern), '#'));
        $this->routen[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pfad = $this->normalisiere($request->path());
        $pfadGefunden = false;

        foreach ($this->routen as $route) {
            if (!preg_match($route['regex'], $pfad, $treffer)) {
                continue;
            }
            $pfadGefunden = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }
            $params = [];
            foreach ($treffer as $name => $wert) {
                if (is_string($name)) {
                    $params[$name] = rawurldecode($wert);
                }
            }
            return ($route['handler'])($request, $params);
        }

        return $pfadGefunden
            ? new Response('Methode nicht erlaubt.', 405)
            : new Response('Seite nicht gefunden.', 404);
    }

    private function normalisiere(string $pfad): string
    {
        $pfad = '/' . trim($pfad, '/');
        return $pfad;
    }
}
