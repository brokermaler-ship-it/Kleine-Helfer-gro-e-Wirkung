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
    ) {
    }
}
