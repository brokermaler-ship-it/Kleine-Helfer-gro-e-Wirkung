<?php

declare(strict_types=1);

namespace Emc\Protokoll;

final class Aenderungsprotokoll
{
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
            $aenderungen === [] ? null : json_encode($aenderungen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
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
