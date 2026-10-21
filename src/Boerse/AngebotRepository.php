<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Codes\Code;
use Emc\Core\Zeitstempel;
use Emc\Fahrzeuge\KonfliktFehler;

/**
 * Angebote der Börse: nur SQL. Bestand und Status verändernde Schreibungen laufen über den AngebotDienst (bzw. die Kasse)
 * in einer Transaktion mit Sperre (boerse → boerse_angebot → magazin_sammlung → magazin_artikel).
 */
final class AngebotRepository
{
    /** Felder, die das Formular „Angebot bearbeiten“ ändert. */
    public const FELDER = ['bezeichnung', 'beschreibung', 'kategorie_id', 'spurweite_id', 'zustand', 'wunschpreis', 'menge', 'person_id', 'erloes_spende', 'boerse_id', 'notiz'];
    public const KONFLIKT = 'Dieses Angebot wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.';
    /** Status, in denen ein Angebot noch verkauft werden kann (und Magazinbestand bindet). */
    public const OFFEN = ['angeboten', 'reserviert'];

    private const SELECT = "SELECT a.*, NULLIF(TRIM(CONCAT(COALESCE(p.vorname, ''), ' ', COALESCE(p.nachname, ''))), '') AS person_name, "
        . 'b.name AS boerse_name, b.status AS boerse_status, b.datum AS boerse_datum, k.name AS kategorie_name, s.name AS spurweite_name, '
        . 'ms.name AS sammlung_name '
        . 'FROM boerse_angebot a JOIN magazin_kategorie k ON k.id = a.kategorie_id '
        . 'LEFT JOIN person p ON p.id = a.person_id '
        . 'LEFT JOIN boerse b ON b.id = a.boerse_id '
        . 'LEFT JOIN spurweite s ON s.id = a.spurweite_id '
        . 'LEFT JOIN magazin_sammlung ms ON ms.id = a.magazin_sammlung_id ';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Nächste Laufnummer des Jahres, atomar auch bei gleichzeitigen Anlagen: die Zeile des Zählers bleibt bis zum Ende
     * der Transaktion gesperrt, LAST_INSERT_ID(…) liefert den Wert dieser Verbindung.
     */
    public function naechsteLaufnummer(int $jahr): int
    {
        $this->pdo->prepare(
            'INSERT INTO boerse_angebot_zaehler (jahr, letzte) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE letzte = LAST_INSERT_ID(letzte + 1)'
        )->execute([$jahr]);
        return (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }

    /**
     * @param array<string,mixed> $d jahr, laufnummer, Felder aus FELDER, herkunft, magazin_artikel_id, magazin_sammlung_id, oeffentlich
     */
    public function anlegen(array $d, ?int $benutzerId): int
    {
        $spalten = ['jahr', 'laufnummer', ...self::FELDER, 'herkunft', 'magazin_artikel_id', 'magazin_sammlung_id', 'oeffentlich', 'angelegt_von_benutzer_id'];
        $werte = [];
        foreach ($spalten as $s) {
            $werte[] = match ($s) {
                'angelegt_von_benutzer_id' => $benutzerId,
                'zustand' => $d['zustand'] ?? 'gebraucht',
                'menge' => (int) ($d['menge'] ?? 1),
                'erloes_spende' => !empty($d['erloes_spende']) ? 1 : 0,
                'oeffentlich' => $d['oeffentlich'] ?? 'nein',
                default => $d[$s] ?? null,
            };
        }
        $this->pdo->prepare('INSERT INTO boerse_angebot (' . implode(', ', $spalten) . ') VALUES (' . implode(', ', array_fill(0, count($spalten), '?')) . ')')
            ->execute($werte);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return ?array<string,mixed> mit nummer, person_name (null ohne Person), boerse_name, boerse_status, boerse_datum,
     *         kategorie_name, spurweite_name, sammlung_name, magazin_code
     */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE a.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::aufbereitet($z);
    }

    /** @return ?array<string,mixed> wie finde */
    public function findeNachNummer(int $jahr, int $lauf): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE a.jahr = ? AND a.laufnummer = ?');
        $st->execute([$jahr, $lauf]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::aufbereitet($z);
    }

    /** Börse des Angebots ohne Sperre (für die Sperrreihenfolge boerse → boerse_angebot). */
    public function boerseVon(int $id): ?int
    {
        $st = $this->pdo->prepare('SELECT boerse_id FROM boerse_angebot WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false || $z['boerse_id'] === null ? null : (int) $z['boerse_id'];
    }

    /**
     * Sperrt das Angebot (SELECT … FOR UPDATE); nur in einer laufenden Transaktion.
     *
     * @return ?array<string,mixed> die gesperrte Zeile
     */
    public function sperre(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM boerse_angebot WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /**
     * @param array<string,mixed> $d Felder aus FELDER
     * @throws KonfliktFehler wenn das Angebot inzwischen geändert wurde
     */
    public function aktualisieren(int $id, array $d, string $stand): void
    {
        if (!Zeitstempel::istGueltig($stand)) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        $werte = array_map(fn(string $f) => match ($f) {
            'zustand' => $d['zustand'] ?? 'gebraucht',
            'menge' => (int) ($d['menge'] ?? 1),
            'erloes_spende' => !empty($d['erloes_spende']) ? 1 : 0,
            default => $d[$f] ?? null,
        }, self::FELDER);
        $st = $this->pdo->prepare("UPDATE boerse_angebot SET $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([...$werte, $id, $stand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
    }

    /** Status (angeboten, reserviert, verkauft, zurueck); ändert bewusst nicht geaendert_am (kein Konflikt für offene Formulare). */
    public function setzeStatus(int $id, string $status, ?int $boerseId = null, bool $boerseSetzen = false): void
    {
        if ($boerseSetzen) {
            $this->pdo->prepare('UPDATE boerse_angebot SET status = ?, boerse_id = ?, geaendert_am = geaendert_am WHERE id = ?')->execute([$status, $boerseId, $id]);
            return;
        }
        $this->pdo->prepare('UPDATE boerse_angebot SET status = ?, geaendert_am = geaendert_am WHERE id = ?')->execute([$status, $id]);
    }

    /** nein, vorgeschlagen oder freigegeben; ändert bewusst nicht geaendert_am. */
    public function setzeOeffentlich(int $id, string $wert): void
    {
        $this->pdo->prepare('UPDATE boerse_angebot SET oeffentlich = ?, geaendert_am = geaendert_am WHERE id = ?')->execute([$wert, $id]);
    }

    /** @return list<array<string,mixed>> wie finde, nach Nummer */
    public function suche(AngebotFilter $f): array
    {
        $wo = [];
        $p = [];
        if ($f->dauerhaft) {
            $wo[] = 'a.boerse_id IS NULL';
        } elseif ($f->boerseId !== null) {
            $wo[] = 'a.boerse_id = ?';
            $p[] = $f->boerseId;
        }
        if ($f->status === null || $f->status === AngebotFilter::OFFEN) {
            $wo[] = "a.status IN ('angeboten', 'reserviert')";
        } elseif ($f->status !== AngebotFilter::ALLE) {
            $wo[] = 'a.status = ?';
            $p[] = $f->status;
        }
        if ($f->oeffentlich !== null) {
            $wo[] = 'a.oeffentlich = ?';
            $p[] = $f->oeffentlich;
        }
        if ($f->herkunft !== null) {
            $wo[] = 'a.herkunft = ?';
            $p[] = $f->herkunft;
        }
        if ($f->personId !== null) {
            $wo[] = 'a.person_id = ?';
            $p[] = $f->personId;
        }
        if ($f->text !== null && trim($f->text) !== '') {
            $text = trim($f->text);
            $muster = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $text) . '%';
            $teile = ["a.bezeichnung LIKE ? ESCAPE '|'", "a.beschreibung LIKE ? ESCAPE '|'"];
            $p[] = $muster;
            $p[] = $muster;
            $nr = Angebotsnummer::zerlege($text);
            if ($nr !== null) {
                $teile[] = '(a.jahr = ? AND a.laufnummer = ?)';
                $p[] = $nr['jahr'];
                $p[] = $nr['lauf'];
            }
            $wo[] = '(' . implode(' OR ', $teile) . ')';
        }
        if ($f->ids !== null) {
            if ($f->ids === []) {
                return [];
            }
            $wo[] = 'a.id IN (' . implode(', ', array_fill(0, count($f->ids), '?')) . ')';
            array_push($p, ...$f->ids);
        }
        $st = $this->pdo->prepare(self::SELECT . ($wo === [] ? '' : 'WHERE ' . implode(' AND ', $wo) . ' ') . 'ORDER BY a.jahr, a.laufnummer');
        $st->execute($p);
        return array_map(self::aufbereitet(...), $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Menge, die offene Einzelangebote (angeboten/reserviert) vom Artikel binden. Vor Migration 017 (Tabelle fehlt) 0.
     *
     * @param ?int $ohneAngebot dieses Angebot nicht mitzählen (beim Ändern der eigenen Menge)
     */
    public function offeneMengeFuerArtikel(int $artikelId, ?int $ohneAngebot = null): int
    {
        try {
            $st = $this->pdo->prepare("SELECT COALESCE(SUM(menge), 0) FROM boerse_angebot WHERE magazin_artikel_id = ? AND status IN ('angeboten', 'reserviert') AND id <> ?");
            $st->execute([$artikelId, $ohneAngebot ?? 0]);
            return (int) $st->fetchColumn();
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return 0;
            }
            throw $e;
        }
    }

    /** Ist die Sammlung in einem offenen Angebot (angeboten/reserviert)? Vor Migration 017 false. */
    public function offenFuerSammlung(int $sammlungId, ?int $ohneAngebot = null): bool
    {
        try {
            $st = $this->pdo->prepare("SELECT 1 FROM boerse_angebot WHERE magazin_sammlung_id = ? AND status IN ('angeboten', 'reserviert') AND id <> ? LIMIT 1");
            $st->execute([$sammlungId, $ohneAngebot ?? 0]);
            return $st->fetchColumn() !== false;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42S02') {
                return false;
            }
            throw $e;
        }
    }

    /** @return list<int> Angebote, denen das Dokument zugeordnet ist (Rückfall der Freigabe nach dem Löschen eines Fotos) */
    public function angeboteMitFoto(int $dokumentId): array
    {
        $st = $this->pdo->prepare("SELECT objekt_id FROM dokument_zuordnung WHERE dokument_id = ? AND objekt_typ = 'boerse_angebot' ORDER BY objekt_id");
        $st->execute([$dokumentId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Entfernt nur die Zuordnung des Dokuments zum Angebot; war es das Titelbild, rückt das älteste verbliebene Foto
     * mit Anzeige-Variante nach.
     *
     * @return ?string Titel des Dokuments, null wenn es dem Angebot nicht zugeordnet war
     */
    public function loeseFoto(int $angebotId, int $dokumentId, bool $warTitelbild): ?string
    {
        $st = $this->pdo->prepare("DELETE FROM dokument_zuordnung WHERE dokument_id = ? AND objekt_typ = 'boerse_angebot' AND objekt_id = ?");
        $st->execute([$dokumentId, $angebotId]);
        if ($st->rowCount() === 0) {
            return null;
        }
        if ($warTitelbild) {
            $st = $this->pdo->prepare(
                "SELECT d.id FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id "
                . "WHERE z.objekt_typ = 'boerse_angebot' AND z.objekt_id = ? AND d.hat_anzeige = 1 ORDER BY d.hochgeladen_am, d.id LIMIT 1"
            );
            $st->execute([$angebotId]);
            $neu = $st->fetchColumn();
            $this->pdo->prepare('UPDATE boerse_angebot SET titelbild_dokument_id = ?, geaendert_am = geaendert_am WHERE id = ?')
                ->execute([$neu === false ? null : (int) $neu, $angebotId]);
        }
        $st = $this->pdo->prepare('SELECT titel FROM dokument WHERE id = ?');
        $st->execute([$dokumentId]);
        return (string) $st->fetchColumn();
    }

    /**
     * Fotos eines Magazinartikels zusätzlich dem Angebot zuordnen (gleiches Dokument, keine Kopie); das Titelbild des
     * Artikels wird Titelbild des Angebots (sonst das älteste Foto mit Anzeige-Variante).
     *
     * @param list<int> $artikelIds
     */
    public function uebernimmFotos(int $angebotId, array $artikelIds): void
    {
        $titel = null;
        foreach ($artikelIds as $artikelId) {
            $this->pdo->prepare(
                "INSERT IGNORE INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) "
                . "SELECT z.dokument_id, 'boerse_angebot', ? FROM dokument_zuordnung z JOIN dokument d ON d.id = z.dokument_id "
                . "WHERE z.objekt_typ = 'magazin_artikel' AND z.objekt_id = ? AND d.kategorie = 'foto'"
            )->execute([$angebotId, $artikelId]);
            if ($titel === null) {
                $st = $this->pdo->prepare('SELECT titelbild_dokument_id FROM magazin_artikel WHERE id = ?');
                $st->execute([$artikelId]);
                $t = $st->fetchColumn();
                $titel = $t === false || $t === null ? null : (int) $t;
            }
        }
        if ($titel === null) {
            $st = $this->pdo->prepare(
                "SELECT d.id FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id "
                . "WHERE z.objekt_typ = 'boerse_angebot' AND z.objekt_id = ? AND d.hat_anzeige = 1 ORDER BY d.hochgeladen_am, d.id LIMIT 1"
            );
            $st->execute([$angebotId]);
            $t = $st->fetchColumn();
            $titel = $t === false ? null : (int) $t;
        }
        if ($titel !== null) {
            $this->pdo->prepare('UPDATE boerse_angebot SET titelbild_dokument_id = ?, geaendert_am = geaendert_am WHERE id = ? AND titelbild_dokument_id IS NULL')
                ->execute([$titel, $angebotId]);
        }
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function aufbereitet(array $z): array
    {
        $z['nummer'] = Angebotsnummer::formatiere((int) $z['jahr'], (int) $z['laufnummer']);
        $z['magazin_code'] = $z['magazin_artikel_id'] === null ? null : Code::magazin((int) $z['magazin_artikel_id']);
        $z['menge'] = (int) $z['menge'];
        return $z;
    }
}
