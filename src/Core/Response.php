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
        /** Pfad einer Datei, die beim Senden direkt ausgegeben wird (statt $body). */
        public readonly ?string $datei = null,
        /** Löscht die Datei nach dem Senden (für Temp-Dateien wie die Sicherung). */
        public readonly bool $loescheNachSenden = false,
    ) {
    }

    public static function redirect(string $url): self
    {
        return new self('', 303, ['Location' => $url]);
    }

    /** @param array<string,string> $headers */
    public static function datei(string $pfad, array $headers = [], bool $loescheNachSenden = false): self
    {
        return new self('', 200, $headers, $pfad, $loescheNachSenden);
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
        if ($this->datei !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            try {
                header('Content-Length: ' . (string) filesize($this->datei));
                readfile($this->datei);
            } finally {
                if ($this->loescheNachSenden) {
                    @unlink($this->datei);
                }
            }
            return;
        }
        echo $this->body;
    }
}
