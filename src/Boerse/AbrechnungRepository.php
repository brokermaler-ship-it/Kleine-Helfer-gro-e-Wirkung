<?php

declare(strict_types=1);

namespace Emc\Boerse;

/**
 * Daten der Abrechnung: nur SQL. Die Abrechnung liest ausschließlich boerse_verkauf_position nicht stornierter Verkäufe
 * (nie Magazinbuchungen – Kassen-Ausgänge und ihre Gegenbuchungen spielen keine Rolle). Auszahlungen schreibt nur der
 * BoerseDienst in seiner Transaktion nach der Sperre der Börse.
 */
final class AbrechnungRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Positionen der nicht stornierten Verkäufe dieser Börse, mit zahlart des Verkaufs und Name der Person.
     *
     * @return list<array{preis:string, provision:string, herkunft:string, person_id:?int, person_name:?string, erloes_spende:bool, zahlart:string}>
     */
    public function positionen(int $boerseId): array
    {
        $st = $this->pdo->prepare(
            "SELECT p.preis, p.provision, p.herkunft, p.person_id, p.erloes_spende, v.zahlart, "
            . "NULLIF(TRIM(CONCAT(COALESCE(pe.vorname, ''), ' ', COALESCE(pe.nachname, ''))), '') AS person_name "
            . 'FROM boerse_verkauf_position p JOIN boerse_verkauf v ON v.id = p.verkauf_id LEFT JOIN person pe ON pe.id = p.person_id '
            . 'WHERE v.boerse_id = ? AND v.storniert_am IS NULL ORDER BY p.id'
        );
        $st->execute([$boerseId]);
        return array_map(fn(array $z): array => [
            'preis' => (string) $z['preis'],
            'provision' => (string) $z['provision'],
            'herkunft' => (string) $z['herkunft'],
            'person_id' => $z['person_id'] === null ? null : (int) $z['person_id'],
            'person_name' => $z['person_name'] === null ? null : (string) $z['person_name'],
            'erloes_spende' => (int) $z['erloes_spende'] === 1,
            'zahlart' => (string) $z['zahlart'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Vermerkte Auszahlungen je Person. Zeilen ohne Person (nach DSGVO-Löschung) stehen unter dem Schlüssel 0,
     * zusammengefasst (Summe, letztes Datum) – passend zur Zeile „ehemaliges Mitglied“ der Abrechnung.
     *
     * @return array<int, array{betrag:string, ausgezahlt_am:string}>
     */
    public function auszahlungen(int $boerseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(person_id, 0) AS person, SUM(betrag) AS betrag, MAX(ausgezahlt_am) AS ausgezahlt_am '
            . 'FROM boerse_auszahlung WHERE boerse_id = ? GROUP BY COALESCE(person_id, 0)'
        );
        $st->execute([$boerseId]);
        $r = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $r[(int) $z['person']] = ['betrag' => (string) $z['betrag'], 'ausgezahlt_am' => (string) $z['ausgezahlt_am']];
        }
        return $r;
    }

    public function auszahlungVermerkt(int $boerseId, int $personId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM boerse_auszahlung WHERE boerse_id = ? AND person_id = ?');
        $st->execute([$boerseId, $personId]);
        return $st->fetchColumn() !== false;
    }

    public function vermerkeAuszahlung(int $boerseId, int $personId, string $betrag, string $datum, ?int $benutzerId): void
    {
        $this->pdo->prepare('INSERT INTO boerse_auszahlung (boerse_id, person_id, betrag, ausgezahlt_am, vermerkt_von_benutzer_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$boerseId, $personId, $betrag, $datum, $benutzerId]);
    }

    /** @return bool true, wenn eine Auszahlung entfernt wurde */
    public function entferneAuszahlung(int $boerseId, int $personId): bool
    {
        $st = $this->pdo->prepare('DELETE FROM boerse_auszahlung WHERE boerse_id = ? AND person_id = ?');
        $st->execute([$boerseId, $personId]);
        return $st->rowCount() > 0;
    }
}
