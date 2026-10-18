<?php

declare(strict_types=1);

namespace Emc\Digital;

final class CvRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function fuerDecoder(int $decoderId): array
    {
        $st = $this->pdo->prepare('SELECT cv, wert, kommentar FROM cv_wert WHERE decoder_id = ? ORDER BY cv');
        $st->execute([$decoderId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function speichern(int $decoderId, int $cv, int $wert, ?string $kommentar): void
    {
        $this->pdo->prepare(
            'INSERT INTO cv_wert (decoder_id, cv, wert, kommentar) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE wert = VALUES(wert), kommentar = VALUES(kommentar)'
        )->execute([$decoderId, $cv, $wert, $kommentar]);
    }

    public function loeschen(int $decoderId, int $cv): void
    {
        $this->pdo->prepare('DELETE FROM cv_wert WHERE decoder_id = ? AND cv = ?')->execute([$decoderId, $cv]);
    }

    public static function pruefe(int $cv, int $wert): ?string
    {
        if ($cv < 1 || $cv > 1024) {
            return 'CV muss zwischen 1 und 1024 liegen.';
        }
        if ($wert < 0 || $wert > 255) {
            return 'Wert muss zwischen 0 und 255 liegen.';
        }
        return null;
    }
}
