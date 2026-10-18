<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

use Emc\Core\DomainFehler;

final class KonfliktFehler extends DomainFehler
{
    public function __construct(string $meldung = 'Dieses Fahrzeug wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.', int $code = 0, ?\Throwable $vorher = null)
    {
        parent::__construct($meldung, $code, $vorher);
    }
}
