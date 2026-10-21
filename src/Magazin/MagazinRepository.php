<?php

declare(strict_types=1);

namespace Emc\Magazin;

use Emc\Codes\Code;
use Emc\Core\DomainFehler;
use Emc\Core\Zeitstempel;
use Emc\Fahrzeuge\KonfliktFehler;

/**
 * Magazin: nur SQL. Der Bestand eines Artikels wird nie gespeichert, sondern immer als SUM(menge) seiner Buchungen
 * gelesen. Bestand verändernde Schreibungen laufen über den MagazinDienst (Transaktion, Sperre des Artikels).
 */
final class MagazinRepository
{
    /** Felder, die das Formular „Artikel bearbeiten“ ändert (der Lagerort nur über eine Umlagerung). */
    public const FELDER = ['bezeichnung', 'kategorie_id', 'hersteller', 'artikelnummer', 'spurweite_id', 'zustand', 'notiz', 'autor_verlag', 'jahr', 'isbn'];
    public const KONFLIKT = 'Dieser Artikel wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.';
    public const KONFLIKT_SAMMLUNG = 'Diese Sammlung wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.';

    private const BESTAND = '(SELECT COALESCE(SUM(b.menge), 0) FROM magazin_buchung b WHERE b.artikel_id = a.id)';
    private const SELECT = 'SELECT a.*, k.name AS kategorie_name, k.schluessel AS kategorie_schluessel, s.name AS spurweite_name, '
        . self::BESTAND . ' AS bestand, sa.sammlung_id, sm.name AS sammlung_name, f.inventarnummer AS fahrzeug_inventarnummer '
        . 'FROM magazin_artikel a JOIN magazin_kategorie k ON k.id = a.kategorie_id '
        . 'LEFT JOIN spurweite s ON s.id = a.spurweite_id '
        . 'LEFT JOIN magazin_sammlung_artikel sa ON sa.artikel_id = a.id '
        . 'LEFT JOIN magazin_sammlung sm ON sm.id = sa.sammlung_id '
        . 'LEFT JOIN fahrzeug f ON f.id = a.fahrzeug_id ';

    public function __construct(private \PDO $pdo)
    {
    }

    // ---- Kategorien -------------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> nach Sortierung, mit anzahl (Artikel der Kategorie) */
    public function kategorien(): array
    {
        return $this->pdo->query(
            'SELECT k.*, (SELECT COUNT(*) FROM magazin_artikel a WHERE a.kategorie_id = k.id) AS anzahl FROM magazin_kategorie k ORDER BY k.sortierung, k.name, k.id'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return ?array<string,mixed> */
    public function kategorie(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM magazin_kategorie WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @return ?array<string,mixed> Kategorie mit festem Logik-Schlüssel (fahrzeuge, literatur, sonstiges) */
    public function kategorieNachSchluessel(string $schluessel): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM magazin_kategorie WHERE schluessel = ?');
        $st->execute([$schluessel]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /**
     * Legt eine Kategorie an ($id null) oder benennt sie um. Der Name ist eindeutig ohne Rücksicht auf Groß-/Kleinschreibung
     * und Akzente (utf8mb4_unicode_ci: „Gebaude“ = „Gebäude“).
     *
     * @throws DomainFehler wenn es den Namen schon gibt
     */
    public function kategorieSpeichern(?int $id, string $name, int $sortierung): int
    {
        try {
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO magazin_kategorie (name, sortierung) VALUES (?, ?)')->execute([trim($name), $sortierung]);
                return (int) $this->pdo->lastInsertId();
            }
            $this->pdo->prepare('UPDATE magazin_kategorie SET name = ?, sortierung = ? WHERE id = ?')->execute([trim($name), $sortierung, $id]);
            return $id;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new DomainFehler('Diese Kategorie gibt es bereits.', 0, $e);
            }
            throw $e;
        }
    }

    /** false, wenn die Kategorie einen festen Schlüssel hat oder noch verwendet wird (dann bleibt sie). */
    public function kategorieLoeschen(int $id): bool
    {
        $k = $this->kategorie($id);
        if ($k === null || $k['schluessel'] !== null) {
            return false;
        }
        try {
            // Fremdschlüssel (RESTRICT) aus Artikeln und später Angeboten verhindern das Löschen einer benutzten Kategorie.
            $st = $this->pdo->prepare('DELETE FROM magazin_kategorie WHERE id = ? AND schluessel IS NULL');
            $st->execute([$id]);
            return $st->rowCount() === 1;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    // ---- Artikel ----------------------------------------------------------------------------------------------------

    /**
     * Legt den Artikel ohne Buchung an (die erste Eingangsbuchung legt der MagazinDienst in derselben Transaktion an).
     *
     * @param array<string,mixed> $d Felder aus FELDER, lagerort, optional fahrzeug_id
     */
    public function anlegen(array $d, ?int $benutzerId): int
    {
        $spalten = [...self::FELDER, 'lagerort', 'fahrzeug_id', 'angelegt_von_benutzer_id'];
        $werte = [];
        foreach ([...self::FELDER, 'lagerort', 'fahrzeug_id'] as $f) {
            $werte[] = $d[$f] ?? ($f === 'zustand' ? 'gebraucht' : null);
        }
        $werte[] = $benutzerId;
        $this->pdo->prepare('INSERT INTO magazin_artikel (' . implode(', ', $spalten) . ') VALUES (' . implode(', ', array_fill(0, count($spalten), '?')) . ')')
            ->execute($werte);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return ?array<string,mixed> mit code, kategorie_name, kategorie_schluessel, spurweite_name, bestand (int),
     *         sammlung_id, sammlung_name, fahrzeug_inventarnummer
     */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE a.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::aufbereitet($z);
    }

    /**
     * Sperrt den Artikel (SELECT … FOR UPDATE) für eine Buchung; nur in einer laufenden Transaktion.
     *
     * @return ?array<string,mixed> die gesperrte Zeile oder null
     */
    public function sperreArtikel(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM magazin_artikel WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /**
     * @param array<string,mixed> $d Felder aus FELDER
     * @throws KonfliktFehler wenn der Artikel inzwischen geändert wurde oder es ihn nicht mehr gibt
     */
    public function aktualisieren(int $id, array $d, string $erwarteterStand): void
    {
        if (!Zeitstempel::istGueltig($erwarteterStand)) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        $werte = array_map(fn(string $f) => $d[$f] ?? ($f === 'zustand' ? 'gebraucht' : null), self::FELDER);
        $st = $this->pdo->prepare("UPDATE magazin_artikel SET $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([...$werte, $id, $erwarteterStand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
    }

    /** Lagerort aus einer Umlagerung; ändert bewusst nicht geaendert_am (das Formular ändert den Lagerort nicht). */
    public function setzeLagerort(int $id, ?string $lagerort): void
    {
        $this->pdo->prepare('UPDATE magazin_artikel SET lagerort = ?, geaendert_am = geaendert_am WHERE id = ?')->execute([$lagerort, $id]);
    }

    /** Ändert bewusst nicht geaendert_am: das Titelbild darf keinen Bearbeitungskonflikt auslösen. */
    public function setzeTitelbild(int $id, ?int $dokumentId): void
    {
        $this->pdo->prepare('UPDATE magazin_artikel SET titelbild_dokument_id = ?, geaendert_am = geaendert_am WHERE id = ?')->execute([$dokumentId, $id]);
    }

    /** @return list<array<string,mixed>> mit code, bestand (int), titelbild_dokument_id, kategorie_name; nach Bezeichnung */
    public function suche(MagazinFilter $f): array
    {
        $wo = [];
        $p = [];
        if ($f->text !== null && trim($f->text) !== '') {
            $text = trim($f->text);
            $muster = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $text) . '%';
            $teile = [];
            foreach (['a.bezeichnung', 'a.hersteller', 'a.artikelnummer', 'a.autor_verlag', 'a.isbn'] as $spalte) {
                $teile[] = "$spalte LIKE ? ESCAPE '|'";
                $p[] = $muster;
            }
            $code = Code::zerlege($text);
            if ($code !== null && $code['typ'] === 'magazin') {
                $teile[] = 'a.id = ?';
                $p[] = $code['nummer'];
            }
            $wo[] = '(' . implode(' OR ', $teile) . ')';
        }
        if ($f->kategorieId !== null) {
            $wo[] = 'a.kategorie_id = ?';
            $p[] = $f->kategorieId;
        }
        if ($f->spurweiteId !== null) {
            $wo[] = 'a.spurweite_id = ?';
            $p[] = $f->spurweiteId;
        }
        if ($f->lagerort !== null) {
            $wo[] = 'a.lagerort = ?';
            $p[] = $f->lagerort;
        }
        if ($f->nurMitBestand) {
            $wo[] = self::BESTAND . ' > 0';
        }
        $st = $this->pdo->prepare(self::SELECT . ($wo === [] ? '' : 'WHERE ' . implode(' AND ', $wo) . ' ') . 'ORDER BY a.bezeichnung, a.id');
        $st->execute($p);
        return array_map(self::aufbereitet(...), $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<string> alle benutzten Lagerorte (für den Filter), alphabetisch */
    public function lagerorte(): array
    {
        return array_map('strval', $this->pdo->query(
            "SELECT DISTINCT lagerort FROM magazin_artikel WHERE lagerort IS NOT NULL AND lagerort <> '' ORDER BY lagerort"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Bestand aus den Buchungen; in einer Transaktion READ COMMITTED erst nach der Sperre des Artikels lesen. */
    public function bestand(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(menge), 0) FROM magazin_buchung WHERE artikel_id = ?');
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    // ---- Fahrzeuge vom Abstellgleis ----------------------------------------------------------------------------------

    /**
     * Sperrt das Fahrzeug (SELECT … FOR UPDATE) vor der Übergabe ans Magazin; nur in einer laufenden Transaktion.
     * Sperrreihenfolge: fahrzeug → magazin_artikel.
     *
     * @return ?array<string,mixed>
     */
    public function sperreFahrzeug(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM fahrzeug WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /**
     * Magazinartikel, der aus dem Fahrzeug entstanden ist. abgegeben_am ist das Datum des letzten Ausgangs, aber nur,
     * wenn kein Bestand mehr da ist.
     *
     * @return ?array{id:int, code:string, bestand:int, abgegeben_am:?string}
     */
    public function fuerFahrzeug(int $fahrzeugId): ?array
    {
        return $this->fuerFahrzeuge([$fahrzeugId])[$fahrzeugId] ?? null;
    }

    /**
     * Wie fuerFahrzeug für mehrere Fahrzeuge (Abstellgleis).
     *
     * @param list<int> $fahrzeugIds
     * @return array<int, array{id:int, code:string, bestand:int, abgegeben_am:?string}> nach Fahrzeug-ID
     */
    public function fuerFahrzeuge(array $fahrzeugIds): array
    {
        $fahrzeugIds = array_values(array_unique(array_map('intval', $fahrzeugIds)));
        if ($fahrzeugIds === []) {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT a.id, a.fahrzeug_id, ' . self::BESTAND . ' AS bestand, '
            . "(SELECT b.datum FROM magazin_buchung b WHERE b.artikel_id = a.id AND b.art = 'ausgang' AND b.menge < 0 ORDER BY b.datum DESC, b.id DESC LIMIT 1) AS letzter_ausgang "
            . 'FROM magazin_artikel a WHERE a.fahrzeug_id IN (' . implode(', ', array_fill(0, count($fahrzeugIds), '?')) . ')'
        );
        $st->execute($fahrzeugIds);
        $ergebnis = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $bestand = (int) $z['bestand'];
            $ergebnis[(int) $z['fahrzeug_id']] = [
                'id' => (int) $z['id'],
                'code' => Code::magazin((int) $z['id']),
                'bestand' => $bestand,
                'abgegeben_am' => $bestand === 0 && $z['letzter_ausgang'] !== null ? (string) $z['letzter_ausgang'] : null,
            ];
        }
        return $ergebnis;
    }

    /** Hängt ein vorhandenes Dokument (Titelfoto des Fahrzeugs) zusätzlich an den Artikel und macht es zum Titelbild. */
    public function uebernimmTitelbild(int $artikelId, int $dokumentId): void
    {
        $this->pdo->prepare("INSERT IGNORE INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES (?, 'magazin_artikel', ?)")
            ->execute([$dokumentId, $artikelId]);
        $this->setzeTitelbild($artikelId, $dokumentId);
    }

    // ---- Buchungen --------------------------------------------------------------------------------------------------

    /**
     * Neue Buchung (unveränderlich). erstellt_am setzt immer die Datenbank (NOW()).
     *
     * @param array<string,mixed> $z art, datum, menge (vorzeichenbehaftet) und optional herkunft, ausgang_art, freitext,
     *                               betrag, lagerort_neu, gegenbuchung_zu_id, verkauf_position_id
     */
    public function bucheZeile(int $artikelId, array $z, ?int $benutzerId): int
    {
        $this->pdo->prepare(
            'INSERT INTO magazin_buchung (artikel_id, art, datum, menge, herkunft, ausgang_art, freitext, betrag, lagerort_neu, gegenbuchung_zu_id, verkauf_position_id, benutzer_id, erstellt_am) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $artikelId,
            $z['art'],
            $z['datum'],
            (int) $z['menge'],
            $z['herkunft'] ?? null,
            $z['ausgang_art'] ?? null,
            $z['freitext'] ?? null,
            $z['betrag'] ?? null,
            $z['lagerort_neu'] ?? null,
            $z['gegenbuchung_zu_id'] ?? null,
            $z['verkauf_position_id'] ?? null,
            $benutzerId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return ?array<string,mixed> Buchung mit gegengebucht_durch_id (ID der Gegenbuchung oder null) */
    public function buchung(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT b.*, (SELECT g.id FROM magazin_buchung g WHERE g.gegenbuchung_zu_id = b.id) AS gegengebucht_durch_id FROM magazin_buchung b WHERE b.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /**
     * Verlauf eines Artikels, neueste zuerst; benutzer_name null, wenn das Konto gelöscht ist.
     *
     * @return list<array<string,mixed>> mit benutzer_name, gegengebucht_durch_id
     */
    public function buchungen(int $artikelId): array
    {
        $st = $this->pdo->prepare(
            "SELECT b.*, NULLIF(TRIM(CONCAT(COALESCE(p.vorname, ''), ' ', COALESCE(p.nachname, ''))), '') AS benutzer_name, "
            . '(SELECT g.id FROM magazin_buchung g WHERE g.gegenbuchung_zu_id = b.id) AS gegengebucht_durch_id '
            . 'FROM magazin_buchung b LEFT JOIN benutzer u ON u.id = b.benutzer_id LEFT JOIN person p ON p.id = u.person_id '
            . 'WHERE b.artikel_id = ? ORDER BY b.datum DESC, b.id DESC'
        );
        $st->execute([$artikelId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ---- Sammlungen -------------------------------------------------------------------------------------------------

    public function sammlungAnlegen(string $name, ?string $notiz): int
    {
        $this->pdo->prepare('INSERT INTO magazin_sammlung (name, notiz) VALUES (?, ?)')->execute([$name, $notiz]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return ?array<string,mixed> mit artikel: list (code, bezeichnung, bestand:int, lagerort, titelbild_dokument_id, kategorie_name) nach id */
    public function sammlung(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM magazin_sammlung WHERE id = ?');
        $st->execute([$id]);
        $s = $st->fetch(\PDO::FETCH_ASSOC);
        if ($s === false) {
            return null;
        }
        $st = $this->pdo->prepare(self::SELECT . 'WHERE sa.sammlung_id = ? ORDER BY a.id');
        $st->execute([$id]);
        $s['artikel'] = array_map(self::aufbereitet(...), $st->fetchAll(\PDO::FETCH_ASSOC));
        return $s;
    }

    /**
     * Sperrt die Sammlung (SELECT … FOR UPDATE); nur in einer laufenden Transaktion.
     *
     * @return ?array<string,mixed>
     */
    public function sperreSammlung(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM magazin_sammlung WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @return list<int> Artikel der Sammlung, aufsteigend nach id */
    public function artikelDerSammlung(int $id): array
    {
        $st = $this->pdo->prepare('SELECT artikel_id FROM magazin_sammlung_artikel WHERE sammlung_id = ? ORDER BY artikel_id');
        $st->execute([$id]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> nach Name, mit artikel_anzahl und bestand (Summe) */
    public function sammlungen(): array
    {
        return $this->pdo->query(
            'SELECT s.*, (SELECT COUNT(*) FROM magazin_sammlung_artikel sa WHERE sa.sammlung_id = s.id) AS artikel_anzahl, '
            . '(SELECT COALESCE(SUM(b.menge), 0) FROM magazin_sammlung_artikel sa JOIN magazin_buchung b ON b.artikel_id = sa.artikel_id WHERE sa.sammlung_id = s.id) AS bestand '
            . 'FROM magazin_sammlung s ORDER BY s.name, s.id'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Artikel, die in eine Sammlung aufgenommen werden können: noch in keiner Sammlung oder schon in dieser.
     *
     * @return list<array<string,mixed>> mit code, bestand, sammlung_id
     */
    public function artikelFuerSammlung(?int $sammlungId): array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE sa.sammlung_id IS NULL OR sa.sammlung_id = ? ORDER BY a.bezeichnung, a.id');
        $st->execute([$sammlungId ?? 0]);
        return array_map(self::aufbereitet(...), $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return ?array{id:int, name:string} Sammlung, zu der der Artikel gehört */
    public function sammlungVonArtikel(int $artikelId): ?array
    {
        $st = $this->pdo->prepare('SELECT s.id, s.name FROM magazin_sammlung_artikel sa JOIN magazin_sammlung s ON s.id = sa.sammlung_id WHERE sa.artikel_id = ?');
        $st->execute([$artikelId]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : ['id' => (int) $z['id'], 'name' => (string) $z['name']];
    }

    /**
     * Name, Notiz und Artikel der Sammlung ersetzen; in der Transaktion des Aufrufers (MagazinDienst::aktualisiereSammlung).
     *
     * @param list<int> $artikelIds
     * @throws KonfliktFehler wenn die Sammlung inzwischen geändert wurde
     */
    public function sammlungAktualisieren(int $id, string $name, ?string $notiz, array $artikelIds, string $stand): void
    {
        if (!Zeitstempel::istGueltig($stand)) {
            throw new KonfliktFehler(self::KONFLIKT_SAMMLUNG);
        }
        $st = $this->pdo->prepare('UPDATE magazin_sammlung SET name = ?, notiz = ?, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?');
        $st->execute([$name, $notiz, $id, $stand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler(self::KONFLIKT_SAMMLUNG);
        }
        $this->pdo->prepare('DELETE FROM magazin_sammlung_artikel WHERE sammlung_id = ?')->execute([$id]);
        $einfuegen = $this->pdo->prepare('INSERT INTO magazin_sammlung_artikel (sammlung_id, artikel_id) VALUES (?, ?)');
        foreach (array_values(array_unique($artikelIds)) as $artikelId) {
            $einfuegen->execute([$id, $artikelId]);
        }
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function aufbereitet(array $z): array
    {
        $z['code'] = Code::magazin((int) $z['id']);
        $z['bestand'] = (int) $z['bestand'];
        return $z;
    }
}
