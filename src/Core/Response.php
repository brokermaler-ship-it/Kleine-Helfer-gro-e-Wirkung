<?php

declare(strict_types=1);

namespace Emc\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function redirect(string $url): self
    {
        return new self('', 303, ['Location' => $url]);
    }

    public static function json(array $daten, int $status = 200): self
    {
        return new self(
            json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $wert) {
            header($name . ': ' . $wert);
        }
        echo $this->body;
    }
}
