<?php

declare(strict_types=1);

namespace Emc\Auth;

final class Benutzer
{
    public function __construct(
        public readonly int $id,
        public readonly int $personId,
        public readonly string $email,
        public readonly string $anzeigename,
        public readonly Rolle $rolle,
        public readonly bool $mussPasswortAendern,
        public readonly string $vorname = '',
        /** Zusatzaufgabe Börsenwart (Häkchen am Konto), unabhängig von der Rolle; siehe Berechtigung::istBoersenwart. */
        public readonly bool $istBoersenwart = false,
    ) {
    }

    /** Rufname für persönliche Ansprache: Vorname der Person, sonst der Anzeigename. */
    public function rufname(): string
    {
        return $this->vorname !== '' ? $this->vorname : $this->anzeigename;
    }
}
