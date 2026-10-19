<?php

declare(strict_types=1);

namespace Emc\Core;

/** Vom Router ausgelöst, wenn keine Route passt (404) oder die Methode nicht erlaubt ist (405). */
final class HttpFehler extends \RuntimeException
{
    /** @param list<string> $erlaubt */
    public function __construct(public readonly int $status, public readonly array $erlaubt = [])
    {
        parent::__construct(match ($status) {
            405 => 'Methode nicht erlaubt.',
            503 => 'Vorübergehend nicht erreichbar.',
            default => 'Seite nicht gefunden.',
        });
    }
}
