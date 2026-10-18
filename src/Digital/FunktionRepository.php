<?php

declare(strict_types=1);

namespace Emc\Digital;

final class FunktionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<int,array{taste:int,beschreibung:string,schaltart:string}> Schlüssel = Taste */
    public function fuerDecoder(int $decoderId): array
    {
        $st = $this->pdo->prepare('SELECT taste, beschreibung, schaltart FROM funktion WHERE decoder_id = ? ORDER BY taste');
        $st->execute([$decoderId]);
        $r = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $r[(int) $z['taste']] = ['taste' => (int) $z['taste'], 'beschreibung' => (string) $z['beschreibung'], 'schaltart' => (string) $z['schaltart']];
        }
        return $r;
    }

    /** Ersetzt die gesamte Belegung; Zeilen ohne Beschreibung entfallen. */
    public function ersetzen(int $decoderId, array $funktionen): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM funktion WHERE decoder_id = ?')->execute([$decoderId]);
            $ins = $this->pdo->prepare('INSERT INTO funktion (decoder_id, taste, beschreibung, schaltart) VALUES (?, ?, ?, ?)');
            foreach ($funktionen as $f) {
                $beschreibung = trim((string) $f['beschreibung']);
                if ($beschreibung === '') {
                    continue;
                }
                $ins->execute([$decoderId, (int) $f['taste'], $beschreibung, $f['schaltart'] === 'moment' ? 'moment' : 'dauer']);
            }
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }
}
