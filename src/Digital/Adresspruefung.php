<?php

declare(strict_types=1);

namespace Emc\Digital;

final class Adresspruefung
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Andere Fahrzeuge (nicht ausgemustert, je Fahrzeug einmal) mit gleicher Spurweite, Protokoll und Adresse.
     *
     * @return list<array{fahrzeug_id:int, inventarnummer:string, bezeichnung:string, decoder_id:int}>
     */
    public function konflikte(int $spurweiteId, Protokoll $p, int $adresse, ?int $ohneFahrzeugId = null): array
    {
        $sql = 'SELECT f.id AS fahrzeug_id, f.inventarnummer, f.bezeichnung, MIN(d.id) AS decoder_id '
            . 'FROM decoder d JOIN fahrzeug f ON f.id = d.fahrzeug_id '
            . "WHERE f.spurweite_id = ? AND d.protokoll = ? AND d.adresse = ? AND f.status <> 'ausgemustert'";
        $param = [$spurweiteId, $p->value, $adresse];
        if ($ohneFahrzeugId !== null) {
            $sql .= ' AND f.id <> ?';
            $param[] = $ohneFahrzeugId;
        }
        $st = $this->pdo->prepare($sql . ' GROUP BY f.id, f.inventarnummer, f.bezeichnung ORDER BY f.inventarnummer, f.id');
        $st->execute($param);
        $liste = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
            $liste[] = [
                'fahrzeug_id' => (int) $z['fahrzeug_id'],
                'inventarnummer' => (string) $z['inventarnummer'],
                'bezeichnung' => (string) $z['bezeichnung'],
                'decoder_id' => (int) $z['decoder_id'],
            ];
        }
        return $liste;
    }

    /**
     * Warntext oder null, wenn kein Konflikt besteht.
     *
     * @param list<array{inventarnummer:string, bezeichnung:string}> $konflikte
     */
    public static function warntext(Protokoll $p, int $adresse, string $spurweiteName, array $konflikte): ?string
    {
        if ($konflikte === []) {
            return null;
        }
        $fahrzeuge = array_map(fn(array $k) => $k['inventarnummer'] . ' ' . $k['bezeichnung'], $konflikte);
        return sprintf(
            'Achtung: Adresse %d (%s) ist in Spur %s bereits vergeben an %s.',
            $adresse,
            $p->label(),
            $spurweiteName,
            implode(', ', array_values(array_unique($fahrzeuge)))
        );
    }

    /**
     * Alle Decoder-Adressen einer Spurweite (ohne ausgemusterte Fahrzeuge).
     *
     * @return list<array<string,mixed>>
     */
    public function uebersicht(int $spurweiteId): array
    {
        $st = $this->pdo->prepare(
            'SELECT d.protokoll, d.adresse, f.id AS fahrzeug_id, f.inventarnummer, f.bezeichnung, '
            . "TRIM(CONCAT(p.vorname, ' ', p.nachname)) AS eigentuemer_name "
            . 'FROM decoder d JOIN fahrzeug f ON f.id = d.fahrzeug_id LEFT JOIN person p ON p.id = f.eigentuemer_person_id '
            . "WHERE f.spurweite_id = ? AND d.adresse IS NOT NULL AND f.status <> 'ausgemustert' "
            . 'ORDER BY d.protokoll, d.adresse, f.inventarnummer, d.id'
        );
        $st->execute([$spurweiteId]);
        $zeilen = $st->fetchAll(\PDO::FETCH_ASSOC);
        // Doppelt = mehrere verschiedene Fahrzeuge; zwei Decoder desselben Fahrzeugs zählen nicht.
        $anzahl = [];
        foreach ($zeilen as $z) {
            $schluessel = $z['protokoll'] . ':' . $z['adresse'];
            $anzahl[$schluessel][(int) $z['fahrzeug_id']] = true;
        }
        $liste = [];
        foreach ($zeilen as $z) {
            $p = Protokoll::from((string) $z['protokoll']);
            $adresse = (int) $z['adresse'];
            $liste[] = [
                'protokoll' => $p,
                'adresse' => $adresse,
                'adressart' => AdressValidator::adressart($p, $adresse),
                'fahrzeug_id' => (int) $z['fahrzeug_id'],
                'inventarnummer' => (string) $z['inventarnummer'],
                'bezeichnung' => (string) $z['bezeichnung'],
                'eigentuemer_name' => $z['eigentuemer_name'] === null ? 'Verein' : (string) $z['eigentuemer_name'],
                'doppelt' => count($anzahl[$z['protokoll'] . ':' . $z['adresse']]) > 1,
            ];
        }
        return $liste;
    }
}
