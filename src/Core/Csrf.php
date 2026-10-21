<?php

declare(strict_types=1);

namespace Emc\Core;

final class Csrf
{
    public const FELD = '_csrf';

    /** @var Session|\Closure():Session */
    private Session|\Closure $session;

    /**
     * @param Session|\Closure():Session $session die Sitzung oder eine Fabrik: dann startet sie erst, wenn ein Token
     *                                          gebraucht wird (öffentliche Fotos und Listen ohne Formular ohne Sitzung)
     */
    public function __construct(Session|\Closure $session)
    {
        $this->session = $session;
    }

    private function sitzung(): Session
    {
        if ($this->session instanceof \Closure) {
            $this->session = ($this->session)();
        }
        return $this->session;
    }

    public function token(): string
    {
        $t = $this->sitzung()->hole('_csrf');
        if (!is_string($t) || $t === '') {
            $t = bin2hex(random_bytes(32));
            $this->sitzung()->setze('_csrf', $t);
        }
        return $t;
    }

    public function pruefe(?string $token): bool
    {
        $soll = $this->sitzung()->hole('_csrf');
        if (!is_string($soll) || $soll === '' || $token === null || $token === '') {
            return false;
        }
        return hash_equals($soll, $token);
    }
}
