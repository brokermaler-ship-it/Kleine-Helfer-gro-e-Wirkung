<?php

declare(strict_types=1);

namespace Emc\Core;

/** Dünne Hülle um die PHP-Sitzung; mit übergebenem Array (Tests) ohne native Sitzung. */
final class Session
{
    /** @var array<string,mixed> */
    private array $daten;
    private bool $nativ;
    private int $erneuerungen = 0;

    /** @param array<string,mixed>|null $speicher */
    public function __construct(?array &$speicher = null)
    {
        if ($speicher !== null) {
            $this->nativ = false;
            $this->daten = &$speicher;
            return;
        }
        $this->nativ = true;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $this->daten = &$_SESSION;
    }

    public function hole(string $k, mixed $d = null): mixed
    {
        return $this->daten[$k] ?? $d;
    }

    public function setze(string $k, mixed $v): void
    {
        $this->daten[$k] = $v;
    }

    public function entferne(string $k): void
    {
        unset($this->daten[$k]);
    }

    /** Neue Sitzungs-ID (gegen Session Fixation) und frisches CSRF-Token. */
    public function erneuere(): void
    {
        if ($this->nativ && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($this->daten['_csrf']);
        $this->erneuerungen++;
    }

    public function erneuerungen(): int
    {
        return $this->erneuerungen;
    }

    /** @param string $typ erfolg|warnung|fehler */
    public function meldung(string $typ, string $text): void
    {
        $liste = $this->daten['_meldungen'] ?? [];
        $liste[] = ['typ' => $typ, 'text' => $text];
        $this->daten['_meldungen'] = $liste;
    }

    /** @return list<array{typ:string,text:string}> */
    public function meldungenAbholen(): array
    {
        $liste = $this->daten['_meldungen'] ?? [];
        unset($this->daten['_meldungen']);
        return array_values($liste);
    }
}
