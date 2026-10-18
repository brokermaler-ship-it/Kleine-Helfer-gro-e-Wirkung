<?php

declare(strict_types=1);

namespace Emc\Dateien;

final class DokumentRepository
{
    public function __construct(private \PDO $pdo, private string $speicherPfad)
    {
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM dokument WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    public function zuordnen(int $dokumentId, string $objektTyp, int $objektId): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES (?, ?, ?)')
            ->execute([$dokumentId, $objektTyp, $objektId]);
    }

    /** Neueste zuerst. @return list<array<string,mixed>> */
    public function fuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare(
            "SELECT d.*, TRIM(CONCAT(COALESCE(p.vorname, ''), ' ', COALESCE(p.nachname, ''))) AS hochgeladen_von_name "
            . 'FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id '
            . 'LEFT JOIN benutzer b ON b.id = d.hochgeladen_von LEFT JOIN person p ON p.id = b.person_id '
            . 'WHERE z.objekt_typ = ? AND z.objekt_id = ? ORDER BY d.hochgeladen_am DESC, d.id DESC'
        );
        $st->execute([$objektTyp, $objektId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<int> IDs der Objekte des Typs, denen das Dokument zugeordnet ist */
    public function objekte(int $dokumentId, string $objektTyp): array
    {
        $st = $this->pdo->prepare('SELECT objekt_id FROM dokument_zuordnung WHERE dokument_id = ? AND objekt_typ = ? ORDER BY objekt_id');
        $st->execute([$dokumentId, $objektTyp]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function istZugeordnet(int $dokumentId, string $objektTyp, int $objektId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM dokument_zuordnung WHERE dokument_id = ? AND objekt_typ = ? AND objekt_id = ?');
        $st->execute([$dokumentId, $objektTyp, $objektId]);
        return $st->fetchColumn() !== false;
    }

    /** Ändert bewusst nicht geaendert_am: das Titelbild darf keinen Bearbeitungskonflikt auslösen. */
    public function setzeTitelbild(int $fahrzeugId, ?int $dokumentId): void
    {
        $this->pdo->prepare('UPDATE fahrzeug SET titelbild_dokument_id = ?, geaendert_am = geaendert_am WHERE id = ?')
            ->execute([$dokumentId, $fahrzeugId]);
    }

    /** Entfernt Datensatz, Zuordnungen, Datei und Varianten; löst Titelbild-Verweise. */
    public function loeschen(int $id): void
    {
        $dok = $this->finde($id);
        if ($dok === null) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE fahrzeug SET titelbild_dokument_id = NULL, geaendert_am = geaendert_am WHERE titelbild_dokument_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM dokument_zuordnung WHERE dokument_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM dokument WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
        $basis = $this->absoluterPfad((string) $dok['pfad']);
        if ($basis !== null) {
            foreach ([$basis, $basis . '.anzeige.jpg', $basis . '.vorschau.jpg'] as $datei) {
                if (is_file($datei)) {
                    @unlink($datei);
                }
            }
        }
    }

    /**
     * Löst alle Dokumente vom Objekt: Dokumente ohne weitere Zuordnung werden samt Dateien gelöscht,
     * geteilte behalten ihre übrigen Zuordnungen.
     */
    public function entferneVonObjekt(string $objektTyp, int $objektId): void
    {
        foreach ($this->fuerObjekt($objektTyp, $objektId) as $dok) {
            $id = (int) $dok['id'];
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = ? AND NOT (objekt_typ = ? AND objekt_id = ?)');
            $st->execute([$id, $objektTyp, $objektId]);
            if ((int) $st->fetchColumn() === 0) {
                $this->loeschen($id);
            } else {
                $this->pdo->prepare('DELETE FROM dokument_zuordnung WHERE dokument_id = ? AND objekt_typ = ? AND objekt_id = ?')
                    ->execute([$id, $objektTyp, $objektId]);
            }
        }
    }

    /** Absoluter Pfad innerhalb des Speicherordners; null bei verdächtigem Pfad. */
    public function absoluterPfad(string $relativ): ?string
    {
        if ($relativ === '' || str_contains($relativ, '..') || str_contains($relativ, "\0") || $relativ[0] === '/' || str_contains($relativ, '\\')) {
            return null;
        }
        return rtrim($this->speicherPfad, '/') . '/' . $relativ;
    }
}
