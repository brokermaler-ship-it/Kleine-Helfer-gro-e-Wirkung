<?php

declare(strict_types=1);

namespace Emc\Betrieb;

final class Einstellungen
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function hole(string $schluessel): ?string
    {
        $st = $this->pdo->prepare('SELECT wert FROM einstellung WHERE schluessel = ?');
        $st->execute([$schluessel]);
        $wert = $st->fetchColumn();
        return $wert === false || $wert === null ? null : (string) $wert;
    }

    public function setze(string $schluessel, string $wert): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO einstellung (schluessel, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)'
        );
        $st->execute([$schluessel, $wert]);
    }
}
