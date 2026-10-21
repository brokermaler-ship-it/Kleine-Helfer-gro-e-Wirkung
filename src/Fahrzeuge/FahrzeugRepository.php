<?php

declare(strict_types=1);

namespace Emc\Fahrzeuge;

final class FahrzeugRepository
{
    private const FELDER = [
        'bezeichnung', 'baureihe', 'bahnverwaltung', 'epoche', 'gattung', 'hersteller', 'artikelnummer',
        'spurweite_id', 'stromsystem', 'eigentuemer_person_id', 'status', 'betriebsstunden', 'notiz',
    ];
    /**
     * Neues ausgemustert_am zum künftigen Status (1. Platzhalter) und heutigen Datum (2. und 3. Platzhalter): bleibt beim
     * Speichern eines schon ausgemusterten Fahrzeugs erhalten, wird beim Wechsel auf „ausgemustert“ heute und sonst
     * geleert. Muss in SET vor „status = ?“ stehen, weil MySQL die Zuweisungen von links nach rechts mit den neuen
     * Werten auswertet. „Heute“ kommt aus PHP (Zeitzone der Anwendung), nicht aus CURDATE() der DB-Sitzung.
     */
    private const AUSGEMUSTERT_AM = "CASE WHEN ? <> 'ausgemustert' THEN NULL WHEN status = 'ausgemustert' THEN COALESCE(ausgemustert_am, ?) ELSE ? END";
    /** Kennzeichen zum künftigen Status (Platzhalter): bleibt bei unverändertem Status, sonst 0. Vor „status = ?“. */
    private const STATUS_AUTOMATISCH = 'status_automatisch = IF(status = ?, status_automatisch, 0)';
    private const STANDARD = ['gattung' => 'lok', 'stromsystem' => '2L', 'status' => 'einsatzbereit', 'betriebsstunden' => 0];

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function suche(FahrzeugFilter $f): array
    {
        $wo = [];
        $p = [];
        if ($f->text !== null) {
            $teile = [];
            foreach (['inventarnummer', 'bezeichnung', 'baureihe', 'hersteller', 'artikelnummer'] as $spalte) {
                $teile[] = "f.$spalte LIKE ? ESCAPE '|'";
                $p[] = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $f->text) . '%';
            }
            $wo[] = '(' . implode(' OR ', $teile) . ')';
        }
        if ($f->spurweiteId !== null) {
            $wo[] = 'f.spurweite_id = ?';
            $p[] = $f->spurweiteId;
        }
        if ($f->eigentuemer === 'verein') {
            $wo[] = 'f.eigentuemer_person_id IS NULL';
        } elseif ($f->eigentuemer === 'mitglied') {
            $wo[] = 'f.eigentuemer_person_id IS NOT NULL';
        }
        if ($f->personId !== null) {
            $wo[] = 'f.eigentuemer_person_id = ?';
            $p[] = $f->personId;
        }
        if ($f->status !== null) {
            $wo[] = 'f.status = ?';
            $p[] = $f->status;
        } elseif ($f->text === null) {
            // Ohne Statusfilter stehen ausgemusterte Fahrzeuge auf dem Abstellgleis; die Textsuche findet sie trotzdem.
            $wo[] = "f.status <> 'ausgemustert'";
        }
        if ($f->gattung !== null) {
            $wo[] = 'f.gattung = ?';
            $p[] = $f->gattung;
        }
        $sql = 'SELECT f.*, s.name AS spurweite_name, '
            . "TRIM(CONCAT(p.vorname, ' ', p.nachname)) AS eigentuemer_name, "
            . "(SELECT GROUP_CONCAT(d.adresse ORDER BY d.protokoll, d.adresse SEPARATOR ', ') FROM decoder d "
            . 'WHERE d.fahrzeug_id = f.id AND d.adresse IS NOT NULL) AS adressen '
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id LEFT JOIN person p ON p.id = f.eigentuemer_person_id '
            . ($wo === [] ? '' : 'WHERE ' . implode(' AND ', $wo) . ' ')
            . 'ORDER BY f.inventarnummer, f.id';
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Nicht ausgemusterte Fahrzeuge, die einer Person gehören (für „Meine Fahrzeuge“). @return list<array<string,mixed>> */
    public function sucheEigene(int $personId): array
    {
        $filter = new FahrzeugFilter(personId: $personId);
        return $this->suche($filter);
    }

    /** Anzahl der Fahrzeuge auf dem Abstellgleis (für das Menü). */
    public function anzahlAbgestellt(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM fahrzeug WHERE status = 'ausgemustert'")->fetchColumn();
    }

    /**
     * Aufs Abstellgleis (Status „ausgemustert“, seit heute) bzw. zurück in Betrieb („einsatzbereit“, ohne Datum).
     * Ein bereits ausgemustertes Fahrzeug behält beim erneuten Abstellen sein Datum. Beides ist eine Änderung von Hand:
     * das Kennzeichen „automatisch durch Reparatur“ wird gelöscht.
     */
    public function setzeAbgestellt(int $id, bool $abgestellt): void
    {
        if ($abgestellt) {
            $sql = "UPDATE fahrzeug SET ausgemustert_am = " . self::AUSGEMUSTERT_AM . ", status = 'ausgemustert', status_automatisch = 0 WHERE id = ?";
            $this->pdo->prepare($sql)->execute(['ausgemustert', self::heute(), self::heute(), $id]);
            return;
        }
        $this->pdo->prepare("UPDATE fahrzeug SET ausgemustert_am = NULL, status = 'einsatzbereit', status_automatisch = 0 WHERE id = ?")->execute([$id]);
    }

    /**
     * Status aus dem automatischen Reparaturstatus (ReparaturDienst::wendeStatusAn) samt Kennzeichen setzen. Ändert
     * geaendert_am: wer das Formular vorher geöffnet hat, bekommt beim Speichern den Bearbeitungskonflikt, statt den
     * neuen Status unbemerkt zu überschreiben. Für „ausgemustert“ nicht gedacht (ausgemustert_am bleibt unberührt).
     */
    public function setzeStatusAutomatisch(int $id, string $status, bool $automatisch): void
    {
        $this->pdo->prepare('UPDATE fahrzeug SET status = ?, status_automatisch = ?, geaendert_am = NOW(6) WHERE id = ?')
            ->execute([$status, $automatisch ? 1 : 0, $id]);
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT f.*, s.name AS spurweite_name, '
            . "TRIM(CONCAT(p.vorname, ' ', p.nachname)) AS eigentuemer_name "
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id LEFT JOIN person p ON p.id = f.eigentuemer_person_id WHERE f.id = ?'
        );
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /**
     * Fahrzeug zum Code F<n>: Inventarnummer EMC-<Zahl> mit dem Zahlenwert n (führende Nullen egal). Gäbe es mehrere
     * Schreibweisen derselben Zahl (EMC-12 und EMC-0012), gewinnt die zuerst angelegte.
     *
     * @return ?array<string,mixed>
     */
    public function findeNachCodeNummer(int $n): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT id FROM fahrzeug WHERE inventarnummer REGEXP '^EMC-[0-9]+$' "
            . 'AND CAST(SUBSTRING(inventarnummer, 5) AS UNSIGNED) = ? ORDER BY id LIMIT 1'
        );
        $st->execute([$n]);
        $id = $st->fetchColumn();
        return $id === false ? null : $this->finde((int) $id);
    }

    /**
     * Verschiedene Fahrzeuge mit einem Decoder dieser Adresse (alle Protokolle, auch ausgemusterte), nach Inventarnummer.
     *
     * @return list<array<string,mixed>> mit spurweite_name, titelbild_dokument_id, bezeichnung, status, inventarnummer und
     *         adress_protokolle (Protokolle der Decoder mit dieser Adresse, z. B. „dcc,motorola“)
     */
    public function nachAdresse(int $adresse): array
    {
        $st = $this->pdo->prepare(
            'SELECT f.*, s.name AS spurweite_name, '
            . "(SELECT GROUP_CONCAT(DISTINCT d2.protokoll ORDER BY d2.protokoll SEPARATOR ',') FROM decoder d2 "
            . 'WHERE d2.fahrzeug_id = f.id AND d2.adresse = ?) AS adress_protokolle '
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id '
            . 'WHERE EXISTS (SELECT 1 FROM decoder d WHERE d.fahrzeug_id = f.id AND d.adresse = ?) '
            . 'ORDER BY f.inventarnummer, f.id'
        );
        $st->execute([$adresse, $adresse]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $d */
    public function anlegen(array $d, ?int $angelegtVonBenutzerId = null): int
    {
        $werte = $this->werte($d);
        $spalten = implode(', ', self::FELDER);
        $platzhalter = implode(', ', array_fill(0, count(self::FELDER) + 2, '?'));
        $status = $werte[array_search('status', self::FELDER, true)];
        $letzter = null;
        for ($versuch = 0; $versuch < 3; $versuch++) {
            $nummer = Inventarnummer::naechste($this->pdo);
            try {
                $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, angelegt_von_benutzer_id, $spalten, ausgemustert_am) "
                    . "VALUES ($platzhalter, ?)")
                    ->execute([$nummer, $angelegtVonBenutzerId, ...$werte, $status === 'ausgemustert' ? self::heute() : null]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_fahrzeug_inventarnummer')) {
                    $letzter = $e; // gleichzeitig vergeben: neu versuchen
                    continue;
                }
                throw $e;
            }
            $id = (int) $this->pdo->lastInsertId();
            Inventarnummer::merke($this->pdo, $nummer);
            return $id;
        }
        throw $letzter ?? new \RuntimeException('Inventarnummer konnte nicht vergeben werden.');
    }

    /**
     * @param array<string,mixed> $d
     * @throws KonfliktFehler wenn der Datensatz inzwischen geändert oder gelöscht wurde
     */
    public function aktualisieren(int $id, array $d, string $erwarteterStand): void
    {
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        // geaendert_am wird immer explizit gesetzt, damit auch ein unverändert gespeichertes Formular 1 Zeile trifft.
        $werte = $this->werte($d);
        $status = $werte[array_search('status', self::FELDER, true)];
        // Ein von Hand geänderter Status löscht das Kennzeichen „automatisch durch Reparatur“; ein unverändert gespeicherter
        // behält es. Steht wie ausgemustert_am vor „status = ?“ (MySQL wertet SET von links nach rechts aus).
        $st = $this->pdo->prepare('UPDATE fahrzeug SET ausgemustert_am = ' . self::AUSGEMUSTERT_AM . ', ' . self::STATUS_AUTOMATISCH
            . ", $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([$status, self::heute(), self::heute(), $status, ...$werte, $id, $erwarteterStand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler();
        }
    }

    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM fahrzeug WHERE id = ?')->execute([$id]);
    }

    /** Heutiges Datum in der Zeitzone der Anwendung (App::setzeZeitzone). */
    private static function heute(): string
    {
        return date('Y-m-d');
    }

    /**
     * @param array<string,mixed> $d
     * @return list<mixed>
     */
    private function werte(array $d): array
    {
        $w = [];
        foreach (self::FELDER as $f) {
            $w[] = $d[$f] ?? self::STANDARD[$f] ?? null;
        }
        return $w;
    }
}
