<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Fahrzeuge\KonfliktFehler;

/**
 * Reparaturen und Defektmeldungen je Objekt (Fahrzeug oder Anlage) samt Ersatzteilen. Nur SQL, keine eigenen
 * Transaktionen: Anlegen bzw. Speichern und der automatische Status (ReparaturDienst::wendeStatusAn) laufen in der
 * Transaktion des Aufrufers.
 */
final class ReparaturRepository
{
    public const PRIORITAETEN = ['dringend' => 'dringend', 'normal' => 'normal', 'niedrig' => 'niedrig'];
    public const KONFLIKT = 'Diese Reparatur wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.';

    /** Felder, die aktualisieren() schreibt (Formular „Reparatur bearbeiten“). */
    public const FELDER = ['beschreibung', 'wo_bauteil', 'prioritaet', 'zustaendig_person_id', 'termin', 'status', 'notizen', 'was_gemacht'];

    private const ABGESCHLOSSEN_SQL = "('erledigt','verworfen')";

    /** Reparatur mit Namen (null, wenn der Bezug fehlt) und Summe der Ersatzteilkosten als String („0.00“ ohne Kosten). */
    private const SELECT = "SELECT r.*, "
        . "CASE WHEN gp.id IS NULL THEN NULL ELSE TRIM(CONCAT(COALESCE(gp.vorname, ''), ' ', COALESCE(gp.nachname, ''))) END AS gemeldet_von_name, "
        . "CASE WHEN zp.id IS NULL THEN NULL ELSE TRIM(CONCAT(COALESCE(zp.vorname, ''), ' ', COALESCE(zp.nachname, ''))) END AS zustaendig_name, "
        . "(SELECT COALESCE(SUM(e.kosten), 0) FROM ersatzteil e WHERE e.reparatur_id = r.id) AS kosten_gesamt "
        . 'FROM reparatur r '
        . 'LEFT JOIN benutzer gb ON gb.id = r.gemeldet_von_benutzer_id LEFT JOIN person gp ON gp.id = gb.person_id '
        . 'LEFT JOIN person zp ON zp.id = r.zustaendig_person_id ';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Neue Meldung mit Status „gemeldet“, gemeldet jetzt.
     *
     * @param array{beschreibung:string, wo_bauteil?:?string, prioritaet?:string} $d
     */
    public function anlegen(string $objektTyp, int $objektId, array $d, ?int $benutzerId): int
    {
        $this->pdo->prepare(
            'INSERT INTO reparatur (objekt_typ, objekt_id, beschreibung, wo_bauteil, gemeldet_von_benutzer_id, gemeldet_am, prioritaet, status) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, 'gemeldet')"
        )->execute([
            $objektTyp,
            $objektId,
            $d['beschreibung'],
            ($d['wo_bauteil'] ?? null) === '' ? null : ($d['wo_bauteil'] ?? null),
            $benutzerId,
            self::jetzt(),
            $d['prioritaet'] ?? 'normal',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return ?array<string,mixed> mit gemeldet_von_name, zustaendig_name, kosten_gesamt und ersatzteile (nach Sortierung)
     */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE r.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        if ($z === false) {
            return null;
        }
        $z['kosten_gesamt'] = (string) $z['kosten_gesamt'];
        $z['ersatzteile'] = $this->ersatzteile($id);
        return $z;
    }

    /** @return list<array<string,mixed>> */
    public function ersatzteile(int $reparaturId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, bezeichnung, artikelnummer, bezugsquelle, kosten, bestellt, sortierung FROM ersatzteil WHERE reparatur_id = ? ORDER BY sortierung, id'
        );
        $st->execute([$reparaturId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array{prioritaet:string, status:string}> offene Reparaturen des Objekts (für StatusAutomatik) */
    public function offeneFuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare(
            'SELECT prioritaet, status FROM reparatur WHERE objekt_typ = ? AND objekt_id = ? AND status IN ' . ReparaturStatus::OFFEN_SQL . ' ORDER BY id'
        );
        $st->execute([$objektTyp, $objektId]);
        return array_map(
            fn(array $z): array => ['prioritaet' => (string) $z['prioritaet'], 'status' => (string) $z['status']],
            $st->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /** @return list<array<string,mixed>> Reparaturen des Objekts: offene zuerst, dann die neuesten Meldungen */
    public function fuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare(
            self::SELECT . 'WHERE r.objekt_typ = ? AND r.objekt_id = ? '
            . 'ORDER BY CASE WHEN r.status IN ' . ReparaturStatus::OFFEN_SQL . ' THEN 0 ELSE 1 END, r.gemeldet_am DESC, r.id DESC'
        );
        $st->execute([$objektTyp, $objektId]);
        return array_map(function (array $z): array {
            $z['kosten_gesamt'] = (string) $z['kosten_gesamt'];
            return $z;
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Speichert die Felder (FELDER) und ersetzt die Ersatzteile. abgeschlossen_am wird beim Wechsel auf erledigt bzw.
     * verworfen jetzt gesetzt (zwischen erledigt und verworfen bleibt es), beim Wiederöffnen geleert.
     *
     * @param array<string,mixed> $d
     * @param list<array{bezeichnung:string, artikelnummer?:?string, bezugsquelle?:?string, kosten?:?string, bestellt?:bool|int|string}> $teile
     * @throws KonfliktFehler wenn die Reparatur inzwischen geändert oder gelöscht wurde
     */
    public function aktualisieren(int $id, array $d, array $teile, string $erwarteterStand): void
    {
        $werte = [];
        foreach (self::FELDER as $f) {
            $w = $d[$f] ?? null;
            $werte[] = $w === '' ? null : $w;
        }
        $status = $d['status'] ?? null;
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        // Vor „status = ?“: MySQL wertet SET von links nach rechts aus, „status“ ist hier noch der alte Wert.
        $abgeschlossen = 'abgeschlossen_am = CASE WHEN ? NOT IN ' . self::ABGESCHLOSSEN_SQL . ' THEN NULL '
            . 'WHEN status IN ' . self::ABGESCHLOSSEN_SQL . ' THEN COALESCE(abgeschlossen_am, ?) ELSE ? END';
        $st = $this->pdo->prepare("UPDATE reparatur SET $abgeschlossen, $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([$status, self::jetzt(), self::jetzt(), ...$werte, $id, $erwarteterStand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
        $this->pdo->prepare('DELETE FROM ersatzteil WHERE reparatur_id = ?')->execute([$id]);
        $ins = $this->pdo->prepare(
            'INSERT INTO ersatzteil (reparatur_id, bezeichnung, artikelnummer, bezugsquelle, kosten, bestellt, sortierung) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($teile) as $i => $t) {
            $leer = fn(mixed $w): mixed => $w === '' ? null : $w;
            $ins->execute([
                $id,
                (string) $t['bezeichnung'],
                $leer($t['artikelnummer'] ?? null),
                $leer($t['bezugsquelle'] ?? null),
                $leer($t['kosten'] ?? null),
                !empty($t['bestellt']) ? 1 : 0,
                $i,
            ]);
        }
    }

    /** @return list<int> alle Reparaturen des Objekts, aufsteigend */
    public function idsFuerObjekt(string $objektTyp, int $objektId): array
    {
        $st = $this->pdo->prepare('SELECT id FROM reparatur WHERE objekt_typ = ? AND objekt_id = ? ORDER BY id');
        $st->execute([$objektTyp, $objektId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Löscht nur den Datensatz (Ersatzteile und Gesehen-Zeilen per CASCADE). Nicht für die Oberfläche – Reparaturen werden
     * verworfen; nur beim Löschen des Objekts und bei der DSGVO-Löschung. Fotos entfernt der Aufrufer vorher.
     */
    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM reparatur WHERE id = ?')->execute([$id]);
    }

    /** Jetzt in der Zeitzone der Anwendung (App::setzeZeitzone), nicht NOW() der DB-Sitzung. */
    private static function jetzt(): string
    {
        return date('Y-m-d H:i:s');
    }
}
