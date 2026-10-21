<?php

declare(strict_types=1);

namespace Emc\Etiketten;

use Emc\Codes\Code;

/**
 * Welche Fahrzeuge bzw. Anlagen ein Etikett bekommen, und der Vermerk „gedruckt“ (Spalte etikett_gedruckt_am,
 * Migration 015). Ausgemusterte Fahrzeuge nur bei der Auswahl „einzeln“. „Noch nicht gedruckt“ enthält nur Fahrzeuge mit
 * Code (Code::fahrzeug) – die übrigen bekommen nie ein Klebchen und blieben sonst für immer „ungedruckt“.
 */
final class EtikettAuswahl
{
    public const FAHRZEUG_AUSWAHL = ['einzeln', 'spurweite', 'loks', 'ungedruckt'];
    public const ANLAGE_AUSWAHL = ['einzeln', 'alle', 'ungedruckt'];
    /** Höchstzahl IDs je Anfrage (Auswahl „einzeln“ und Vermerk). */
    public const MAX_IDS = 5000;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<int> $ids nur bei „einzeln“
     * @return list<array<string,mixed>> mit id, inventarnummer, bezeichnung, status, gattung, spurweite_name, etikett_gedruckt_am
     */
    public function fahrzeuge(string $auswahl, array $ids, ?int $spurweiteId): array
    {
        $bedingungen = [];
        $werte = [];
        switch ($auswahl) {
            case 'einzeln':
                $ids = self::bereinige($ids);
                if ($ids === []) {
                    return [];
                }
                $bedingungen[] = 'f.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $werte = $ids;
                break;
            case 'spurweite':
                if ($spurweiteId === null) {
                    return [];
                }
                $bedingungen[] = 'f.spurweite_id = ?';
                $werte[] = $spurweiteId;
                break;
            case 'loks':
                $bedingungen[] = "f.gattung IN ('lok', 'triebwagen')";
                break;
            case 'ungedruckt':
                $bedingungen[] = 'f.etikett_gedruckt_am IS NULL';
                break;
            default:
                return [];
        }
        if ($auswahl !== 'einzeln') {
            $bedingungen[] = "f.status <> 'ausgemustert'";
        }
        $st = $this->pdo->prepare(
            'SELECT f.id, f.inventarnummer, f.bezeichnung, f.status, f.gattung, f.etikett_gedruckt_am, s.name AS spurweite_name '
            . 'FROM fahrzeug f JOIN spurweite s ON s.id = f.spurweite_id WHERE ' . implode(' AND ', $bedingungen)
            . ' ORDER BY f.inventarnummer, f.id'
        );
        $st->execute($werte);
        $zeilen = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($auswahl === 'ungedruckt') {
            $zeilen = array_values(array_filter($zeilen, fn(array $f): bool => Code::fahrzeug((string) $f['inventarnummer']) !== null));
        }
        return $zeilen;
    }

    /**
     * Inventarnummern der Fahrzeuge ohne Code, die sonst unter „noch nicht gedruckt“ stünden (nicht ausgemustert, nie
     * vermerkt) – die Auswahlseite nennt sie getrennt.
     *
     * @return list<string>
     */
    public function ungedrucktOhneCode(): array
    {
        $alle = $this->pdo->query(
            "SELECT inventarnummer FROM fahrzeug WHERE etikett_gedruckt_am IS NULL AND status <> 'ausgemustert' ORDER BY inventarnummer, id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map('strval', $alle), fn(string $nr): bool => Code::fahrzeug($nr) === null));
    }

    /**
     * @param list<int> $ids nur bei „einzeln“
     * @return list<array<string,mixed>> mit id, nummer, name, spurweite_name, etikett_gedruckt_am; sortiert nach Nummer
     */
    public function anlagen(string $auswahl, array $ids): array
    {
        $bedingung = match ($auswahl) {
            'einzeln' => null,
            'alle' => '1 = 1',
            'ungedruckt' => 'a.etikett_gedruckt_am IS NULL',
            default => false,
        };
        if ($bedingung === false) {
            return [];
        }
        $werte = [];
        if ($bedingung === null) {
            $werte = self::bereinige($ids);
            if ($werte === []) {
                return [];
            }
            $bedingung = 'a.id IN (' . implode(',', array_fill(0, count($werte), '?')) . ')';
        }
        $st = $this->pdo->prepare(
            'SELECT a.id, a.nummer, a.name, a.etikett_gedruckt_am, s.name AS spurweite_name '
            . 'FROM anlage a JOIN spurweite s ON s.id = a.spurweite_id WHERE ' . $bedingung . ' ORDER BY a.nummer'
        );
        $st->execute($werte);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int,int> Spurweiten-ID => Anzahl Fahrzeuge (ohne ausgemusterte) für die Auswahl „Spurweite“ */
    public function anzahlJeSpurweite(): array
    {
        $zeilen = $this->pdo->query(
            "SELECT spurweite_id, COUNT(*) FROM fahrzeug WHERE status <> 'ausgemustert' GROUP BY spurweite_id"
        )->fetchAll(\PDO::FETCH_NUM);
        $anzahl = [];
        foreach ($zeilen as [$id, $n]) {
            $anzahl[(int) $id] = (int) $n;
        }
        return $anzahl;
    }

    /**
     * Setzt etikett_gedruckt_am auf jetzt, ohne geaendert_am zu berühren (der Vermerk ist keine Bearbeitung und soll
     * kein offenes Formular in einen Konflikt laufen lassen).
     *
     * @param string $typ fahrzeug oder anlage
     * @param list<int> $ids
     * @return int Anzahl vorhandener (vermerkter) Objekte
     */
    public function vermerkeGedruckt(string $typ, array $ids): int
    {
        $tabelle = match ($typ) {
            'fahrzeug' => 'fahrzeug',
            'anlage' => 'anlage',
            default => throw new \InvalidArgumentException('Unbekannter Typ: ' . $typ),
        };
        $ids = self::bereinige($ids);
        if ($ids === []) {
            return 0;
        }
        $platz = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM $tabelle WHERE id IN ($platz)");
        $st->execute($ids);
        $anzahl = (int) $st->fetchColumn();
        $this->pdo->prepare("UPDATE $tabelle SET etikett_gedruckt_am = NOW(), geaendert_am = geaendert_am WHERE id IN ($platz)")
            ->execute($ids);
        return $anzahl;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int> positive, eindeutige Ganzzahlen (höchstens MAX_IDS)
     */
    public static function bereinige(array $ids): array
    {
        $sauber = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $sauber[$id] = $id;
            } elseif (is_string($id) && preg_match('/^[1-9][0-9]{0,9}$/', trim($id)) === 1 && (int) trim($id) <= 4294967295) {
                $sauber[(int) trim($id)] = (int) trim($id);
            }
            if (count($sauber) >= self::MAX_IDS) {
                break;
            }
        }
        return array_values($sauber);
    }
}
