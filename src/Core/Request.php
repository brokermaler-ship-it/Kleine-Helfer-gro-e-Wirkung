<?php

declare(strict_types=1);

namespace Emc\Core;

final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $files = [],
        private array $server = [],
    ) {
        $this->method = strtoupper($method);
    }

    /** @param array<string,mixed> $config */
    public static function fromGlobals(array $config = []): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $pfad = (string) parse_url($uri, PHP_URL_PATH);
        $pfad = rawurldecode($pfad);

        $basis = rtrim((string) ($config['basis_url'] ?? ''), '/');
        if ($basis !== '') {
            $basisPfad = (string) parse_url($basis, PHP_URL_PATH);
            if ($basisPfad !== '' && str_starts_with($pfad, $basisPfad)) {
                $pfad = substr($pfad, strlen($basisPfad));
            }
        }
        if ($pfad === '' || $pfad[0] !== '/') {
            $pfad = '/' . $pfad;
        }

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $pfad,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
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

    /** POST hat Vorrang vor GET. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
