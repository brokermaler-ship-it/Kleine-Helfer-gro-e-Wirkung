<?php

declare(strict_types=1);

namespace Emc\Mail;

/** Eine Textnachricht an genau einen Empfänger. */
final readonly class Nachricht
{
    public function __construct(public string $an, public string $betreff, public string $text)
    {
    }
}
