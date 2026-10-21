<?php

declare(strict_types=1);

namespace Emc\Anlagen;

use Emc\Fahrzeuge\KonfliktFehler;

final class AnlageRepository
{
    public const FELDER = ['name', 'spurweite_id', 'kurzbeschreibung', 'standort', 'status'];
    public const KONFLIKT = 'Diese Anlage wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.';

    private const SELECT = 'SELECT a.*, s.name AS spurweite_name FROM anlage a JOIN spurweite s ON s.id = a.spurweite_id ';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Alle Anlagen nach Code, optional nach Spurweite und Text (Name, Standort, Kurzbeschreibung oder Code „A3“).
     *
     * @return list<array<string,mixed>> mit spurweite_name und code
     */
    public function alle(?int $spurweiteId = null, ?string $text = null): array
    {
        $wo = [];
        $p = [];
        if ($spurweiteId !== null) {
            $wo[] = 'a.spurweite_id = ?';
            $p[] = $spurweiteId;
        }
        $text = $text === null ? '' : trim($text);
        if ($text !== '') {
            $muster = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $text) . '%';
            $teile = [];
            foreach (['a.name', 'a.standort', 'a.kurzbeschreibung'] as $spalte) {
                $teile[] = "$spalte LIKE ? ESCAPE '|'";
                $p[] = $muster;
            }
            if (preg_match('/^a\s*(\d{1,9})$/i', $text, $m) === 1) {
                $teile[] = 'a.nummer = ?';
                $p[] = (int) $m[1];
            }
            $wo[] = '(' . implode(' OR ', $teile) . ')';
        }
        $st = $this->pdo->prepare(self::SELECT . ($wo === [] ? '' : 'WHERE ' . implode(' AND ', $wo) . ' ') . 'ORDER BY a.nummer');
        $st->execute($p);
        return array_map(self::mitCode(...), $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE a.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::mitCode($z);
    }

    /** @return ?array<string,mixed> Anlage mit dem Code A<nummer> */
    public function findeNachNummer(int $nummer): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE a.nummer = ?');
        $st->execute([$nummer]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : self::mitCode($z);
    }

    /**
     * Legt die Anlage an und vergibt die nächste Nummer in einer Transaktion (in einer laufenden Transaktion des
     * Aufrufers ohne eigene). Wurde dieselbe Nummer gleichzeitig vergeben, wird neu gezählt.
     *
     * @param array<string,mixed> $d
     */
    public function anlegen(array $d): int
    {
        $eigene = !$this->pdo->inTransaction();
        $spalten = implode(', ', self::FELDER);
        $platzhalter = implode(', ', array_fill(0, count(self::FELDER) + 1, '?'));
        $letzter = null;
        for ($versuch = 0; $versuch < 3; $versuch++) {
            if ($eigene) {
                $this->pdo->beginTransaction();
            }
            try {
                $nummer = AnlagenCode::naechste($this->pdo);
                $this->pdo->prepare("INSERT INTO anlage (nummer, $spalten) VALUES ($platzhalter)")
                    ->execute([$nummer, ...$this->werte($d)]);
                $id = (int) $this->pdo->lastInsertId();
                AnlagenCode::merke($this->pdo, $nummer);
                if ($eigene) {
                    $this->pdo->commit();
                }
                return $id;
            } catch (\Throwable $t) {
                if ($eigene && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($t instanceof \PDOException && (string) $t->getCode() === '23000' && str_contains($t->getMessage(), 'uq_anlage_nummer')) {
                    $letzter = $t; // gleichzeitig vergeben: neu versuchen
                    continue;
                }
                throw $t;
            }
        }
        throw $letzter ?? new \RuntimeException('Die Anlagennummer konnte nicht vergeben werden.');
    }

    /**
     * @param array<string,mixed> $d
     * @throws KonfliktFehler wenn der Datensatz inzwischen geändert oder gelöscht wurde
     */
    public function aktualisieren(int $id, array $d, string $erwarteterStand): void
    {
        $zuweisung = implode(', ', array_map(fn(string $f) => "$f = ?", self::FELDER));
        // geaendert_am wird immer explizit gesetzt, damit auch ein unverändert gespeichertes Formular 1 Zeile trifft.
        $st = $this->pdo->prepare("UPDATE anlage SET $zuweisung, geaendert_am = NOW(6) WHERE id = ? AND geaendert_am = ?");
        $st->execute([...$this->werte($d), $id, $erwarteterStand]);
        if ($st->rowCount() === 0) {
            throw new KonfliktFehler(self::KONFLIKT);
        }
    }

    /** Löscht nur den Datensatz; Dateien entfernt der Aufrufer vorher (DokumentRepository::entferneVonObjekt). */
    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM anlage WHERE id = ?')->execute([$id]);
    }

    /** Ändert bewusst nicht geaendert_am: das Titelbild darf keinen Bearbeitungskonflikt auslösen. */
    public function setzeTitelbild(int $id, ?int $dokumentId): void
    {
        $this->pdo->prepare('UPDATE anlage SET titelbild_dokument_id = ?, geaendert_am = geaendert_am WHERE id = ?')
            ->execute([$dokumentId, $id]);
    }

    /**
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function mitCode(array $z): array
    {
        $z['code'] = AnlagenCode::formatiere((int) $z['nummer']);
        return $z;
    }

    /**
     * @param array<string,mixed> $d
     * @return list<mixed>
     */
    private function werte(array $d): array
    {
        $w = [];
        foreach (self::FELDER as $f) {
            $w[] = $d[$f] ?? ($f === 'status' ? 'in_ordnung' : null);
        }
        return $w;
    }
}
