<?php

declare(strict_types=1);

namespace Emc\Reparatur;

/**
 * Die gewählte zuständige Person gibt es beim Speichern unter der Sperre nicht mehr (zwischen Prüfung und Speichern
 * gelöscht). ReparaturController zeigt dann das Formular mit Hinweis (422); die Transaktion wird zurückgerollt.
 */
final class ZustaendigFehlt extends \RuntimeException
{
}
