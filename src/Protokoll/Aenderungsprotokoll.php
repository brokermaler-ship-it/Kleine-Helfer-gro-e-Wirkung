<?php

declare(strict_types=1);

namespace Emc\Protokoll;

final class Aenderungsprotokoll
{
    /** Ersatztext für entfernte Personenbezüge. */
    public const EHEMALIGES_MITGLIED = 'ehemaliges Mitglied';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function __construct(private \PDO $pdo)
    {
    }

    /** @param array<string,array{alt:mixed,neu:mixed}> $aenderungen */
    public function schreibe(?int $benutzerId, string $objektTyp, int $objektId, string $aktion, array $aenderungen = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO aenderungsprotokoll (benutzer_id, objekt_typ, objekt_id, aktion, aenderungen, zeitpunkt) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $benutzerId,
            $objektTyp,
            $objektId,
            $aktion,
            $aenderungen === [] ? null : json_encode($aenderungen, self::JSON_FLAGS | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array{id:int,zeitpunkt:string,aktion:string,anzeigename:?string,aenderungen:array<string,array{alt:mixed,neu:mixed}>}> */
    public function fuerObjekt(string $objektTyp, int $objektId, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.zeitpunkt, a.aktion, a.aenderungen, p.vorname, p.nachname '
            . 'FROM aenderungsprotokoll a '
            . 'LEFT JOIN benutzer b ON b.id = a.benutzer_id '
            . 'LEFT JOIN person p ON p.id = b.person_id '
            . 'WHERE a.objekt_typ = ? AND a.objekt_id = ? ORDER BY a.id DESC LIMIT ' . max(1, $limit)
        );
        $st->execute([$objektTyp, $objektId]);
        $liste = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $name = $z['vorname'] === null ? null : trim($z['vorname'] . ' ' . $z['nachname']);
            $liste[] = [
                'id' => (int) $z['id'],
                'zeitpunkt' => (string) $z['zeitpunkt'],
                'aktion' => (string) $z['aktion'],
                'anzeigename' => $name,
                'aenderungen' => $z['aenderungen'] === null ? [] : (array) json_decode((string) $z['aenderungen'], true),
            ];
        }
        return $liste;
    }

    /**
     * DSGVO: entfernt die Einträge der eigenen Objekte der Person, ersetzt in allen übrigen Einträgen jede Fundstelle
     * eines Begriffs (ohne Groß-/Kleinschreibung, längere Begriffe zuerst) in den Werten von `aenderungen` durch
     * „ehemaliges Mitglied“ und leert `benutzer_id` für das Konto. Schlüssel und Nicht-Text-Werte bleiben unverändert.
     * Keine eigene Transaktion.
     *
     * @param list<string> $begriffe z. B. „Vorname Nachname“, „Nachname, Vorname“, E-Mail, Telefon; leere, zu kurze und ungültige werden ignoriert
     * @param list<array{0:string,1:int}> $eigeneObjekte z. B. [['person', 17], ['benutzer', 5]]
     * @return int Anzahl gelöschter und geänderter Einträge (jeder Eintrag einmal)
     */
    public function anonymisiere(array $begriffe, ?int $benutzerId, array $eigeneObjekte): int
    {
        $anzahl = 0;
        $loeschen = $this->pdo->prepare('DELETE FROM aenderungsprotokoll WHERE objekt_typ = ? AND objekt_id = ?');
        foreach ($eigeneObjekte as [$typ, $id]) {
            $loeschen->execute([(string) $typ, (int) $id]);
            $anzahl += $loeschen->rowCount();
        }

        /** @var array<int,true> $geaendert */
        $geaendert = [];
        $liste = self::bereinigeBegriffe($begriffe);
        if ($liste !== []) {
            $aendern = $this->pdo->prepare('UPDATE aenderungsprotokoll SET aenderungen = ? WHERE id = ?');
            // Nur ganze Fundstellen: kein Buchstabe/keine Ziffer direkt davor oder danach („Carla Mustermann“ bleibt).
            $muster = '/(?<![\\p{L}\\p{N}])(?:' . implode('|', array_map(fn(string $b): string => preg_quote($b, '/'), $liste)) . ')(?![\\p{L}\\p{N}])/iu';
            foreach ($this->kandidaten($liste) as $z) {
                $roh = (string) $z['aenderungen'];
                $daten = json_decode($roh, true);
                if (is_array($daten)) {
                    $neu = self::ersetzeWerte($daten, $muster);
                    $text = $neu === $daten ? null : json_encode($neu, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
                } else {
                    // Kein gültiges JSON: dann den Text selbst bereinigen, damit nichts stehen bleibt.
                    $ersetzt = self::ersetze($roh, $muster);
                    $text = $ersetzt === $roh ? null : $ersetzt;
                }
                if ($text !== null) {
                    $aendern->execute([$text, (int) $z['id']]);
                    $geaendert[(int) $z['id']] = true;
                }
            }
        }

        if ($benutzerId !== null) {
            $st = $this->pdo->prepare('SELECT id FROM aenderungsprotokoll WHERE benutzer_id = ?');
            $st->execute([$benutzerId]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $geaendert[(int) $id] = true;
            }
            $this->pdo->prepare('UPDATE aenderungsprotokoll SET benutzer_id = NULL WHERE benutzer_id = ?')->execute([$benutzerId]);
        }

        return $anzahl + count($geaendert);
    }

    /** Kürzere Begriffe würden zu viele fremde Stellen treffen (z. B. Datumsteile). */
    private const MINDESTLAENGE = 4;

    /**
     * Getrimmt, ohne ungültiges UTF-8, ohne zu kurze (< 4 Zeichen), ohne Dubletten (Groß-/Kleinschreibung egal), längste zuerst.
     *
     * @param list<string> $begriffe
     * @return list<string>
     */
    private static function bereinigeBegriffe(array $begriffe): array
    {
        $liste = [];
        foreach ($begriffe as $b) {
            $b = (string) $b;
            if (!mb_check_encoding($b, 'UTF-8')) {
                continue;
            }
            $b = trim($b);
            if (mb_strlen($b) >= self::MINDESTLAENGE) {
                $liste[mb_strtolower($b)] ??= $b;
            }
        }
        $liste = array_values($liste);
        usort($liste, fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        return $liste;
    }

    /**
     * Vorauswahl per LIKE (Platzhalter % und _ maskiert); die eigentliche Ersetzung geschieht in PHP.
     *
     * @param list<string> $begriffe
     * @return list<array{id:mixed,aenderungen:mixed}>
     */
    private function kandidaten(array $begriffe): array
    {
        $werte = [];
        foreach ($begriffe as $b) {
            // Roh und so, wie schreibe() ihn in JSON ablegt (z. B. mit maskierten Anführungszeichen).
            foreach ([$b, substr((string) json_encode($b, self::JSON_FLAGS), 1, -1)] as $form) {
                $werte['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $form) . '%'] = true;
            }
        }
        $werte = array_keys($werte);
        $bedingung = implode(' OR ', array_fill(0, count($werte), "aenderungen LIKE ? ESCAPE '!'"));
        $st = $this->pdo->prepare('SELECT id, aenderungen FROM aenderungsprotokoll WHERE aenderungen IS NOT NULL AND (' . $bedingung . ') ORDER BY id');
        $st->execute($werte);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Ersetzt rekursiv nur Text-Werte; Schlüssel bleiben. */
    private static function ersetzeWerte(mixed $wert, string $muster): mixed
    {
        if (is_string($wert)) {
            return self::ersetze($wert, $muster);
        }
        if (is_array($wert)) {
            foreach ($wert as $k => $w) {
                $wert[$k] = self::ersetzeWerte($w, $muster);
            }
        }
        return $wert;
    }

    private static function ersetze(string $text, string $muster): string
    {
        $ergebnis = preg_replace($muster, self::EHEMALIGES_MITGLIED, $text);
        if ($ergebnis === null) {
            throw new \RuntimeException('Anonymisierung fehlgeschlagen: ' . preg_last_error_msg());
        }
        return $ergebnis;
    }

    /**
     * Nur geänderte Schlüssel aus $neu. Zahl und Formular-String sowie null und '' gelten als gleich.
     *
     * @param array<string,mixed> $alt
     * @param array<string,mixed> $neu
     * @return array<string,array{alt:mixed,neu:mixed}>
     */
    public static function diff(array $alt, array $neu): array
    {
        $ergebnis = [];
        foreach ($neu as $feld => $wert) {
            $vorher = $alt[$feld] ?? null;
            if (self::normal($vorher) !== self::normal($wert)) {
                $ergebnis[$feld] = ['alt' => $vorher, 'neu' => $wert];
            }
        }
        return $ergebnis;
    }

    private static function normal(mixed $w): string
    {
        if ($w === null) {
            return '';
        }
        if (is_bool($w)) {
            return $w ? '1' : '0';
        }
        return is_scalar($w) ? (string) $w : (string) json_encode($w);
    }
}
