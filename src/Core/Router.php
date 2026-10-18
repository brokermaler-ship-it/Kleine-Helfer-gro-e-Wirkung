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

    /** Liefert bei fehlender Route eine schlichte 404-/405-Antwort. */
    public function dispatch(Request $request): Response
    {
        try {
            return $this->dispatchOderWirf($request);
        } catch (HttpFehler $f) {
            $header = $f->erlaubt !== [] ? ['Allow' => implode(', ', $f->erlaubt)] : [];
            return new Response($f->getMessage(), $f->status, $header);
        }
    }

    /** @throws HttpFehler wenn keine Route passt (404) oder die Methode nicht erlaubt ist (405) */
    public function dispatchOderWirf(Request $request): Response
    {
        $pfad = $this->normalisiere($request->path());
        $erlaubt = [];

        foreach ($this->routen as $route) {
            if (!preg_match($route['regex'], $pfad, $treffer)) {
                continue;
            }
            if ($route['method'] !== $request->method()) {
                $erlaubt[] = $route['method'];
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

        throw new HttpFehler($erlaubt !== [] ? 405 : 404, array_values(array_unique($erlaubt)));
    }

    private function normalisiere(string $pfad): string
    {
        $pfad = '/' . trim($pfad, '/');
        return $pfad;
    }
}
