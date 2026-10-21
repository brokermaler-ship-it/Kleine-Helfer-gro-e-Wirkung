<?php

declare(strict_types=1);

namespace Emc\Wartung;

use Emc\Anlagen\AnlagenCode;

/**
 * Stellt die Fälligkeiten zusammen: je Objekt die aktiven, passenden und nicht abgeschalteten Wartungsarten
 * mit ihrem (ggf. eigenen) Intervall, der letzten Wartung und der Ampel. Ausgemusterte Fahrzeuge zählen nicht.
 */
final class Wartungsplan
{
    /** Höchstzahl der Einträge „die dringendsten“ auf der Startseite. */
    public const DRINGENDSTE = 5;

    public function __construct(
        private \PDO $pdo,
        private WartungsartRepository $arten,
        private WartungRepository $wartungen,
        private AusnahmeRepository $ausnahmen,
    ) {
    }

    /**
     * Fälligkeiten eines Objekts in der Reihenfolge der Wartungsarten.
     *
     * @param array<string,mixed> $objekt Zeile aus fahrzeug bzw. anlage (id, bei Fahrzeugen gattung, status, betriebsstunden)
     * @return list<array{art: array<string,mixed>, monate: ?int, stunden: ?int, eigenes_intervall: bool, letzte: ?array{datum:string, stundenstand:?float}, faelligkeit: Faelligkeit}>
     */
    public function fuerObjekt(string $objektTyp, array $objekt, \DateTimeImmutable $heute): array
    {
        if ($objektTyp === 'fahrzeug' && ($objekt['status'] ?? null) === 'ausgemustert') {
            return [];
        }
        $id = (int) $objekt['id'];
        $arten = $this->arten->passendeFuer($objektTyp, $objektTyp === 'fahrzeug' ? (string) ($objekt['gattung'] ?? '') : null);
        if ($arten === []) {
            return [];
        }
        $ausnahmen = $this->ausnahmen->fuerObjekt($objektTyp, $id);
        $letzte = $this->wartungen->letzteJeArt($objektTyp, $id);
        $ergebnis = [];
        foreach ($arten as $art) {
            $zeile = self::zeile($objektTyp, $objekt, $art, $ausnahmen[$art['id']] ?? null, $letzte["$objektTyp:$id:{$art['id']}"] ?? null, $heute);
            if ($zeile !== null) {
                $ergebnis[] = $zeile;
            }
        }
        return $ergebnis;
    }

    /**
     * Alle Fälligkeiten, sortiert nach Dringlichkeit: Ampel (Rot zuerst), innerhalb Gelb mit Datum vor „noch nie erfasst“,
     * dann verbleibender Anteil, dann Objektname. Lädt Arten, Ausnahmen, letzte Wartungen und Objekte mit je einer Abfrage.
     *
     * @return list<array{objekt_typ:string, objekt: array<string,mixed>, art: array<string,mixed>, monate: ?int, stunden: ?int, eigenes_intervall: bool, letzte: ?array{datum:string, stundenstand:?float}, faelligkeit: Faelligkeit}>
     */
    public function uebersicht(WartungFilter $f, \DateTimeImmutable $heute): array
    {
        $arten = array_values(array_filter($this->arten->alle(), fn(array $a): bool => $a['aktiv'] === 1));
        $objekte = [];
        if ($f->mitFahrzeugen()) {
            foreach ($this->fahrzeuge($f) as $fz) {
                $objekte[] = ['fahrzeug', $fz];
            }
        }
        if ($f->mitAnlagen()) {
            foreach ($this->anlagen($f) as $a) {
                $objekte[] = ['anlage', $a];
            }
        }
        if ($arten === [] || $objekte === []) {
            return [];
        }
        $ausnahmen = $this->ausnahmen->alle();
        $letzte = [];
        foreach (['fahrzeug', 'anlage'] as $typ) {
            if (($typ === 'fahrzeug' && $f->mitFahrzeugen()) || ($typ === 'anlage' && $f->mitAnlagen())) {
                $letzte += $this->wartungen->letzteJeArt($typ);
            }
        }

        $ergebnis = [];
        foreach ($objekte as [$typ, $objekt]) {
            $gattung = $typ === 'fahrzeug' ? (string) $objekt['gattung'] : null;
            $schluessel = $typ . ':' . (int) $objekt['id'] . ':';
            foreach ($arten as $art) {
                if (!GiltFuer::from((string) $art['gilt_fuer'])->passt($typ, $gattung)) {
                    continue;
                }
                $zeile = self::zeile($typ, $objekt, $art, $ausnahmen[$schluessel . $art['id']] ?? null, $letzte[$schluessel . $art['id']] ?? null, $heute);
                if ($zeile !== null) {
                    $ergebnis[] = ['objekt_typ' => $typ, 'objekt' => $objekt] + $zeile;
                }
            }
        }
        usort($ergebnis, [self::class, 'vergleiche']);
        return $ergebnis;
    }

    /**
     * Für die Kachel auf der Startseite.
     *
     * @return array{rot:int, gelb:int, dringendste: list<array<string,mixed>>} dringendste: höchstens fünf Einträge, nur Rot/Gelb
     */
    public function zusammenfassung(\DateTimeImmutable $heute): array
    {
        $rot = 0;
        $gelb = 0;
        $dringendste = [];
        foreach ($this->uebersicht(new WartungFilter(), $heute) as $z) {
            if ($z['faelligkeit']->ampel === Ampel::Gruen) {
                break; // sortiert: danach kommt nur noch Grün
            }
            $z['faelligkeit']->ampel === Ampel::Rot ? $rot++ : $gelb++;
            if (count($dringendste) < self::DRINGENDSTE) {
                $dringendste[] = $z;
            }
        }
        return ['rot' => $rot, 'gelb' => $gelb, 'dringendste' => $dringendste];
    }

    /** Anzeigename eines Objekts (für Sortierung und Anzeige): Bezeichnung bzw. Name. */
    public static function objektName(string $objektTyp, array $objekt): string
    {
        return (string) ($objektTyp === 'anlage' ? ($objekt['name'] ?? '') : ($objekt['bezeichnung'] ?? ''));
    }

    /** Kennung: Inventarnummer bzw. Code A<n>. */
    public static function objektKennung(string $objektTyp, array $objekt): string
    {
        return (string) ($objektTyp === 'anlage' ? ($objekt['code'] ?? AnlagenCode::formatiere((int) ($objekt['nummer'] ?? 0))) : ($objekt['inventarnummer'] ?? ''));
    }

    /**
     * @param array<string,mixed> $objekt
     * @param array<string,mixed> $art
     * @param ?array{aus:bool, intervall_monate:?int, intervall_stunden:?int} $ausnahme
     * @param ?array{datum:string, stundenstand:?float} $letzte
     * @return ?array{art: array<string,mixed>, monate: ?int, stunden: ?int, eigenes_intervall: bool, letzte: ?array{datum:string, stundenstand:?float}, faelligkeit: Faelligkeit}
     */
    private static function zeile(string $objektTyp, array $objekt, array $art, ?array $ausnahme, ?array $letzte, \DateTimeImmutable $heute): ?array
    {
        if ($ausnahme !== null && $ausnahme['aus']) {
            return null;
        }
        $eigen = $ausnahme !== null;
        $monate = $eigen ? $ausnahme['intervall_monate'] : $art['intervall_monate'];
        $stunden = $eigen ? $ausnahme['intervall_stunden'] : $art['intervall_stunden'];
        $aktuell = null;
        if ($objektTyp === 'anlage') {
            $stunden = null; // Anlagen haben keine Betriebsstunden.
        } else {
            $aktuell = isset($objekt['betriebsstunden']) ? (float) $objekt['betriebsstunden'] : null;
        }
        return [
            'art' => $art,
            'monate' => $monate,
            'stunden' => $stunden,
            'eigenes_intervall' => $eigen,
            'letzte' => $letzte,
            'faelligkeit' => Faelligkeitsrechner::berechne(
                $monate,
                $stunden,
                $letzte === null ? null : new \DateTimeImmutable($letzte['datum']),
                $letzte['stundenstand'] ?? null,
                $aktuell,
                $heute
            ),
        ];
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function vergleiche(array $a, array $b): int
    {
        $fa = $a['faelligkeit'];
        $fb = $b['faelligkeit'];
        return [$fa->ampel->rang(), $fa->nochNieErfasst, $fa->rest, mb_strtolower(self::objektName($a['objekt_typ'], $a['objekt'])), self::objektKennung($a['objekt_typ'], $a['objekt']), $a['art']['sortierung'], $a['art']['id']]
            <=> [$fb->ampel->rang(), $fb->nochNieErfasst, $fb->rest, mb_strtolower(self::objektName($b['objekt_typ'], $b['objekt'])), self::objektKennung($b['objekt_typ'], $b['objekt']), $b['art']['sortierung'], $b['art']['id']];
    }

    /** @return list<array<string,mixed>> nicht ausgemusterte Fahrzeuge nach Filter */
    private function fahrzeuge(WartungFilter $f): array
    {
        $wo = ["f.status <> 'ausgemustert'"];
        $p = [];
        if ($f->spurweiteId !== null) {
            $wo[] = 'f.spurweite_id = ?';
            $p[] = $f->spurweiteId;
        }
        if ($f->eigentuemer === 'verein') {
            $wo[] = 'f.eigentuemer_person_id IS NULL';
        } elseif ($f->eigentuemer === 'mitglied') {
            $wo[] = 'f.eigentuemer_person_id IS NOT NULL';
        }
        $st = $this->pdo->prepare(
            'SELECT f.id, f.inventarnummer, f.bezeichnung, f.gattung, f.status, f.betriebsstunden, f.spurweite_id, f.eigentuemer_person_id, s.name AS spurweite_name '
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id WHERE ' . implode(' AND ', $wo) . ' ORDER BY f.inventarnummer, f.id'
        );
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> Anlagen nach Filter (mit Code) */
    private function anlagen(WartungFilter $f): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.nummer, a.name, a.spurweite_id, s.name AS spurweite_name FROM anlage a JOIN spurweite s ON s.id = a.spurweite_id '
            . ($f->spurweiteId !== null ? 'WHERE a.spurweite_id = ? ' : '') . 'ORDER BY a.nummer'
        );
        $st->execute($f->spurweiteId !== null ? [$f->spurweiteId] : []);
        return array_map(function (array $z): array {
            $z['code'] = AnlagenCode::formatiere((int) $z['nummer']);
            return $z;
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }
}
