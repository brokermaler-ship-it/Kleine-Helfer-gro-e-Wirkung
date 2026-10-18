<?php

declare(strict_types=1);

namespace Emc\Core;

final class Csrf
{
    public const FELD = '_csrf';

    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $t = $this->session->hole('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $this->session->setze('_csrf', $t);
        }
        return $t;
    }

    public function pruefe(?string $token): bool
    {
        $soll = $this->session->hole('_csrf');
        if (!is_string($soll) || $soll === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($soll, $token);
    }
}
