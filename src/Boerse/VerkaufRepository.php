<?php

declare(strict_types=1);

namespace Emc\Boerse;

/**
 * Verkäufe der Kasse samt Positionen: nur SQL. Geschrieben wird nur vom KasseDienst in seiner Transaktion (Verkauf,
 * Storno); Herkunft, Person, Spende und Provision stehen in der Position fest.
 */
final class VerkaufRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function anlegen(int $boerseId, string $summe, string $zahlart, \DateTimeImmutable $verkauftAm, ?int $benutzerId): int
    {
        $this->pdo->prepare('INSERT INTO boerse_verkauf (boerse_id, summe, zahlart, verkauft_am, kassiert_von_benutzer_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$boerseId, $summe, $zahlart, $verkauftAm->format('Y-m-d H:i:s'), $benutzerId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function position(int $verkaufId, int $angebotId, string $preis, string $provision, string $herkunft, ?int $personId, bool $spende): int
    {
        $this->pdo->prepare('INSERT INTO boerse_verkauf_position (verkauf_id, angebot_id, preis, provision, herkunft, person_id, erloes_spende) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$verkaufId, $angebotId, $preis, $provision, $herkunft, $personId, $spende ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return ?array<string,mixed> */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM boerse_verkauf WHERE id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @return ?array<string,mixed> gesperrte Zeile (nach der Sperre der Börse) */
    public function sperre(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM boerse_verkauf WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @return list<array<string,mixed>> Positionen mit nummer, nach Angebots-id */
    public function positionen(int $verkaufId): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.*, a.jahr, a.laufnummer, a.bezeichnung FROM boerse_verkauf_position p JOIN boerse_angebot a ON a.id = p.angebot_id '
            . 'WHERE p.verkauf_id = ? ORDER BY p.angebot_id, p.id'
        );
        $st->execute([$verkaufId]);
        return array_map(function (array $z): array {
            $z['nummer'] = Angebotsnummer::formatiere((int) $z['jahr'], (int) $z['laufnummer']);
            return $z;
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Magazinbuchungen (Ausgänge) der Kasse zu diesem Verkauf, ohne Gegenbuchungen; nach Artikel-id aufsteigend
     * (Sperrreihenfolge).
     *
     * @return list<array{id:int, artikel_id:int, verkauf_position_id:int}>
     */
    public function buchungen(int $verkaufId): array
    {
        $st = $this->pdo->prepare(
            'SELECT b.id, b.artikel_id, b.verkauf_position_id FROM magazin_buchung b JOIN boerse_verkauf_position p ON p.id = b.verkauf_position_id '
            . 'WHERE p.verkauf_id = ? AND b.gegenbuchung_zu_id IS NULL ORDER BY b.artikel_id, b.id'
        );
        $st->execute([$verkaufId]);
        return array_map(fn(array $z): array => ['id' => (int) $z['id'], 'artikel_id' => (int) $z['artikel_id'], 'verkauf_position_id' => (int) $z['verkauf_position_id']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param list<int> $personIds */
    public function auszahlungVermerkt(int $boerseId, array $personIds): bool
    {
        if ($personIds === []) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT 1 FROM boerse_auszahlung WHERE boerse_id = ? AND person_id IN (' . implode(', ', array_fill(0, count($personIds), '?')) . ') LIMIT 1');
        $st->execute([$boerseId, ...$personIds]);
        return $st->fetchColumn() !== false;
    }

    public function storniere(int $id, string $grund, \DateTimeImmutable $jetzt, ?int $benutzerId): void
    {
        $this->pdo->prepare('UPDATE boerse_verkauf SET storniert_am = ?, storno_grund = ?, storniert_von_benutzer_id = ? WHERE id = ?')
            ->execute([$jetzt->format('Y-m-d H:i:s'), $grund, $benutzerId, $id]);
    }

    /**
     * Verkäufe einer Börse, neueste zuerst, je mit nummern (list<string>) und kassiert_von (Name bzw. null).
     *
     * @return list<array<string,mixed>>
     */
    public function fuerBoerse(int $boerseId): array
    {
        $st = $this->pdo->prepare(
            "SELECT v.*, NULLIF(TRIM(CONCAT(COALESCE(pe.vorname, ''), ' ', COALESCE(pe.nachname, ''))), '') AS kassiert_von "
            . 'FROM boerse_verkauf v LEFT JOIN benutzer bn ON bn.id = v.kassiert_von_benutzer_id LEFT JOIN person pe ON pe.id = bn.person_id '
            . 'WHERE v.boerse_id = ? ORDER BY v.verkauft_am DESC, v.id DESC'
        );
        $st->execute([$boerseId]);
        $verkaeufe = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($verkaeufe === []) {
            return [];
        }
        $ids = array_map(fn(array $v): int => (int) $v['id'], $verkaeufe);
        $st = $this->pdo->prepare(
            'SELECT p.verkauf_id, a.jahr, a.laufnummer FROM boerse_verkauf_position p JOIN boerse_angebot a ON a.id = p.angebot_id '
            . 'WHERE p.verkauf_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY a.jahr, a.laufnummer'
        );
        $st->execute($ids);
        $nummern = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $nummern[(int) $z['verkauf_id']][] = Angebotsnummer::formatiere((int) $z['jahr'], (int) $z['laufnummer']);
        }
        foreach ($verkaeufe as $i => $v) {
            $verkaeufe[$i]['nummern'] = $nummern[(int) $v['id']] ?? [];
        }
        return $verkaeufe;
    }
}
