<?php

declare(strict_types=1);

namespace Emc\Mail;

/** Zeilenweiser Zugriff auf einen SMTP-Server (in Tests ersetzt, damit kein Netz nötig ist). */
interface SmtpVerbindung
{
    /** @param bool $ssl true: verschlüsselt ab dem ersten Byte (ssl://), false: Klartext bis STARTTLS */
    public function oeffne(string $host, int $port, bool $ssl, int $zeitlimit): void;

    /** Schreibt eine Zeile und hängt CRLF an. */
    public function schreibe(string $zeile): void;

    /**
     * Liest eine (ggf. mehrzeilige, „250-…“) Antwort.
     *
     * @return array{code:int, text:string}
     */
    public function liesAntwort(): array;

    /** Schaltet nach STARTTLS die Verschlüsselung ein (mit Zertifikatsprüfung). */
    public function starteTls(): void;

    public function schliesse(): void;
}
