<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Anlagen\AnlagenCode;
use Emc\Codes\Code;
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
    private const SELECT = self::SPALTEN . 'FROM reparatur r ' . self::PERSONEN_JOIN;
    private const SPALTEN = "SELECT r.*, "
        . "CASE WHEN gp.id IS NULL THEN NULL ELSE TRIM(CONCAT(COALESCE(gp.vorname, ''), ' ', COALESCE(gp.nachname, ''))) END AS gemeldet_von_name, "
        . "CASE WHEN zp.id IS NULL THEN NULL ELSE TRIM(CONCAT(COALESCE(zp.vorname, ''), ' ', COALESCE(zp.nachname, ''))) END AS zustaendig_name, "
        . "(SELECT COALESCE(SUM(e.kosten), 0) FROM ersatzteil e WHERE e.reparatur_id = r.id) AS kosten_gesamt ";
    private const PERSONEN_JOIN = 'LEFT JOIN benutzer gb ON gb.id = r.gemeldet_von_benutzer_id LEFT JOIN person gp ON gp.id = gb.person_id '
        . 'LEFT JOIN person zp ON zp.id = r.zustaendig_person_id ';

    /** Spalten des Objekts (Fahrzeug bzw. Anlage) für mitKennung(); passend zu OBJEKT_JOIN. */
    private const OBJEKT_SPALTEN = 'f.inventarnummer, f.bezeichnung, a.nummer AS anlage_nummer, a.name AS anlage_name, '
        . 'COALESCE(f.titelbild_dokument_id, a.titelbild_dokument_id) AS titelbild_dokument_id, '
        . 'COALESCE(fs.name, asw.name) AS spurweite_name ';
    private const OBJEKT_JOIN = "LEFT JOIN fahrzeug f ON r.objekt_typ = 'fahrzeug' AND f.id = r.objekt_id "
        . "LEFT JOIN anlage a ON r.objekt_typ = 'anlage' AND a.id = r.objekt_id "
        . 'LEFT JOIN spurweite fs ON fs.id = f.spurweite_id LEFT JOIN spurweite asw ON asw.id = a.spurweite_id ';

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
     * Meldungen eines Benutzers („Meine Meldungen“), neueste zuerst, mit Objektkennung (Code F<n> bzw. A<n>, sonst die
     * Inventarnummer) und Objektname; beides null, wenn das Objekt fehlt.
     *
     * @return list<array<string,mixed>> mit objekt_kennung, objekt_name, titelbild_dokument_id
     */
    public function vonBenutzer(int $benutzerId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.id, r.objekt_typ, r.objekt_id, r.beschreibung, r.wo_bauteil, r.prioritaet, r.status, r.gemeldet_am, '
            . self::OBJEKT_SPALTEN
            . 'FROM reparatur r ' . self::OBJEKT_JOIN
            . 'WHERE r.gemeldet_von_benutzer_id = ? ORDER BY r.gemeldet_am DESC, r.id DESC'
        );
        $st->execute([$benutzerId]);
        return array_map(self::mitKennung(...), $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Seite „Reparaturen“: nach Status („offen“, ein Statuswert oder „alle“), Objekttyp und zuständiger Person gefiltert;
     * dringend vor normal vor niedrig, dann neueste Meldung zuerst. Mit Objektkennung (Code bzw. Inventarnummer),
     * Objektname, Spurweite, Namen und kosten_gesamt (String, Summe in SQL).
     *
     * @return list<array<string,mixed>>
     */
    public function suche(ReparaturFilter $f): array
    {
        $wo = [];
        $p = [];
        $status = $f->status ?? ReparaturFilter::OFFEN;
        if ($status === ReparaturFilter::OFFEN) {
            $wo[] = 'r.status IN ' . ReparaturStatus::OFFEN_SQL;
        } elseif ($status !== ReparaturFilter::ALLE) {
            $wo[] = 'r.status = ?';
            $p[] = $status;
        }
        if ($f->typ !== null) {
            $wo[] = 'r.objekt_typ = ?';
            $p[] = $f->typ;
        }
        if ($f->zustaendigId !== null) {
            $wo[] = 'r.zustaendig_person_id = ?';
            $p[] = $f->zustaendigId;
        }
        $st = $this->pdo->prepare(
            self::SPALTEN . ', ' . self::OBJEKT_SPALTEN . 'FROM reparatur r ' . self::PERSONEN_JOIN . self::OBJEKT_JOIN
            . ($wo === [] ? '' : 'WHERE ' . implode(' AND ', $wo) . ' ')
            . "ORDER BY FIELD(r.prioritaet, 'dringend', 'normal', 'niedrig'), r.gemeldet_am DESC, r.id DESC"
        );
        $st->execute($p);
        return array_map(function (array $z): array {
            $z['kosten_gesamt'] = (string) $z['kosten_gesamt'];
            return self::mitKennung($z);
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * objekt_kennung (Code F<n> bzw. A<n>, sonst die Inventarnummer) und objekt_name aus den Spalten von OBJEKT_SPALTEN;
     * beides null, wenn das Objekt fehlt.
     *
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function mitKennung(array $z): array
    {
        if ($z['objekt_typ'] === 'fahrzeug') {
            $z['objekt_kennung'] = $z['inventarnummer'] === null ? null : (Code::fahrzeug((string) $z['inventarnummer']) ?? (string) $z['inventarnummer']);
            $z['objekt_name'] = $z['bezeichnung'];
        } else {
            $z['objekt_kennung'] = $z['anlage_nummer'] === null ? null : AnlagenCode::formatiere((int) $z['anlage_nummer']);
            $z['objekt_name'] = $z['anlage_name'];
        }
        return $z;
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
        // Kein Zeitstempel (Feld fehlt oder manipuliert): wie ein veralteter Stand behandeln, nicht als SQL-Fehler.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $erwarteterStand) !== 1) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
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
