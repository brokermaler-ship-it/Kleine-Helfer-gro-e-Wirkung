<?php

declare(strict_types=1);

namespace Emc\Dateien;

use Emc\OneDrive\Auftraege;

final class DokumentRepository
{
    public function __construct(private \PDO $pdo, private string $speicherPfad, private ?Auftraege $auftraege = null)
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
        $offen = "(SELECT COUNT(*) FROM onedrive_auftrag oa WHERE oa.dokument_id = d.id AND oa.art = 'hochladen' AND oa.status = 'wartet')";
        try {
            return $this->ladeFuerObjekt($objektTyp, $objektId, $offen);
        } catch (\PDOException) {
            // Neue Programmdateien, aber Datenbank-Update (Migration 004) noch nicht ausgeführt: ohne OneDrive-Angaben weiter.
            return $this->ladeFuerObjekt($objektTyp, $objektId, '0');
        }
    }

    /** @return list<array<string,mixed>> */
    private function ladeFuerObjekt(string $objektTyp, int $objektId, string $offen): array
    {
        $st = $this->pdo->prepare(
            'SELECT d.*, (SELECT COUNT(*) FROM dokument_zuordnung za WHERE za.dokument_id = d.id) AS zuordnungen_anzahl, ' . $offen . " AS onedrive_offen, TRIM(CONCAT(COALESCE(p.vorname, ''), ' ', COALESCE(p.nachname, ''))) AS hochgeladen_von_name "
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

    /** @return list<array{objekt_typ:string,objekt_id:int}> alle Zuordnungen des Dokuments, gleich welchen Typs */
    public function zuordnungen(int $dokumentId): array
    {
        $st = $this->pdo->prepare('SELECT objekt_typ, objekt_id FROM dokument_zuordnung WHERE dokument_id = ? ORDER BY objekt_typ, objekt_id');
        $st->execute([$dokumentId]);
        return array_map(
            fn(array $z): array => ['objekt_typ' => (string) $z['objekt_typ'], 'objekt_id' => (int) $z['objekt_id']],
            $st->fetchAll(\PDO::FETCH_ASSOC)
        );
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

    /** Objekttypen mit eigener Spalte titelbild_dokument_id, deren Titelbild über setzeTitelbildWennLeer gesetzt wird. */
    private const TITELBILD_TABELLEN = ['magazin_artikel' => 'magazin_artikel', 'boerse_angebot' => 'boerse_angebot'];

    public static function hatTitelbild(string $objektTyp): bool
    {
        return isset(self::TITELBILD_TABELLEN[$objektTyp]);
    }

    /**
     * Setzt das Titelbild eines Magazinartikels bzw. Börsenangebots, solange keins gesetzt ist. Ändert bewusst nicht
     * geaendert_am (kein Bearbeitungskonflikt durch ein Foto).
     */
    public function setzeTitelbildWennLeer(string $objektTyp, int $objektId, int $dokumentId): void
    {
        $tabelle = self::TITELBILD_TABELLEN[$objektTyp] ?? throw new \InvalidArgumentException('Objekttyp ohne Titelbild: ' . $objektTyp);
        $this->pdo->prepare("UPDATE $tabelle SET titelbild_dokument_id = ?, geaendert_am = geaendert_am WHERE id = ? AND titelbild_dokument_id IS NULL")
            ->execute([$dokumentId, $objektId]);
    }

    /** Entfernt Datensatz, Zuordnungen, Datei und Varianten; löst Titelbild-Verweise (Fahrzeuge, Anlagen, Magazinartikel, Angebote). */
    public function loeschen(int $id): void
    {
        if ($this->finde($id) === null) {
            return; // wie bisher: ohne Datensatz keine Transaktion
        }
        $this->pdo->beginTransaction();
        try {
            $pfad = $this->loescheDatensatz($id);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
        if ($pfad !== null) {
            $this->entferneDateien($pfad);
        }
    }

    /**
     * Entfernt Datensatz, Zuordnungen und Titelbild-Verweise und legt bei OneDrive-Ablage den Lösch-Auftrag an –
     * ohne eigene Transaktion und ohne Dateien anzufassen (der Aufrufer entfernt sie nach seinem Commit mit
     * entferneDateien()). Liefert den relativen Pfad oder null, wenn es das Dokument nicht gibt.
     */
    public function loescheDatensatz(int $id): ?string
    {
        $dok = $this->finde($id);
        if ($dok === null) {
            return null;
        }
        // Lösch-Auftrag zuerst und in derselben Transaktion: ohne ihn bliebe die Datei im OneDrive verwaist.
        if ($this->auftraege !== null && ($dok['ablage'] ?? 'lokal') === 'onedrive' && is_string($dok['onedrive_item_id'] ?? null) && $dok['onedrive_item_id'] !== '') {
            $drive = is_string($dok['onedrive_drive_id'] ?? null) ? $dok['onedrive_drive_id'] : null;
            $this->auftraege->neu('loeschen', null, $dok['onedrive_item_id'], null, $drive);
        }
        $this->pdo->prepare('UPDATE fahrzeug SET titelbild_dokument_id = NULL, geaendert_am = geaendert_am WHERE titelbild_dokument_id = ?')->execute([$id]);
        // anlage ab Migration 011, magazin_artikel ab 016, boerse_angebot ab 017: vor dem Datenbank-Update fehlt die Tabelle.
        foreach (['anlage', ...array_values(self::TITELBILD_TABELLEN)] as $tabelle) {
            try {
                $this->pdo->prepare("UPDATE $tabelle SET titelbild_dokument_id = NULL, geaendert_am = geaendert_am WHERE titelbild_dokument_id = ?")->execute([$id]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '42S02') {
                    throw $e;
                }
            }
        }
        $this->pdo->prepare('DELETE FROM dokument_zuordnung WHERE dokument_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM dokument WHERE id = ?')->execute([$id]);
        return (string) $dok['pfad'];
    }

    /**
     * Entfernt Original, Anzeige- und Vorschauvariante vom Webspace; verdächtige Pfade werden ignoriert.
     *
     * @return bool false, wenn eine vorhandene Datei nicht entfernt werden konnte
     */
    public function entferneDateien(string $relativerPfad): bool
    {
        $basis = $this->absoluterPfad($relativerPfad);
        if ($basis === null) {
            return true;
        }
        $ok = true;
        foreach ([$basis, $basis . '.anzeige.jpg', $basis . '.vorschau.jpg'] as $datei) {
            if (is_file($datei) && !@unlink($datei)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** @return list<int> Dokumente, die nur diesem Objekt zugeordnet sind (geteilte nicht), aufsteigend */
    public function idsFuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare(
            'SELECT z.dokument_id FROM dokument_zuordnung z WHERE z.objekt_typ = ? AND z.objekt_id = ? '
            . 'AND NOT EXISTS (SELECT 1 FROM dokument_zuordnung w WHERE w.dokument_id = z.dokument_id AND NOT (w.objekt_typ = z.objekt_typ AND w.objekt_id = z.objekt_id)) '
            . 'ORDER BY z.dokument_id'
        );
        $st->execute([$objektTyp, $objektId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
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

    /**
     * Wie entferneVonObjekt(), aber in der Transaktion des Aufrufers und ohne Dateien anzufassen: Dokumente ohne weitere
     * Zuordnung verlieren ihren Datensatz (loescheDatensatz), geteilte nur diese Zuordnung. Sperrende Lesungen, damit eine
     * gleichzeitig angelegte Zuordnung weder übersehen noch ohne ihr Dokument gelöscht wird.
     *
     * @return list<string> relative Pfade, die der Aufrufer nach seinem Commit mit entferneDateien() entfernt
     */
    public function loeseVonObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare('SELECT dokument_id FROM dokument_zuordnung WHERE objekt_typ = ? AND objekt_id = ? ORDER BY dokument_id FOR UPDATE');
        $st->execute([$objektTyp, $objektId]);
        $andere = $this->pdo->prepare('SELECT COUNT(*) FROM dokument_zuordnung WHERE dokument_id = ? AND NOT (objekt_typ = ? AND objekt_id = ?) FOR UPDATE');
        $pfade = [];
        foreach (array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)) as $id) {
            $andere->execute([$id, $objektTyp, $objektId]);
            if ((int) $andere->fetchColumn() === 0) {
                $pfad = $this->loescheDatensatz($id);
                if ($pfad !== null) {
                    $pfade[] = $pfad;
                }
            }
        }
        $this->pdo->prepare('DELETE FROM dokument_zuordnung WHERE objekt_typ = ? AND objekt_id = ?')->execute([$objektTyp, $objektId]);
        return $pfade;
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
