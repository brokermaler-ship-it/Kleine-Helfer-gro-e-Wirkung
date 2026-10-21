<?php

declare(strict_types=1);

namespace Emc\Boerse;

/** Anfragen aus dem öffentlichen Bereich (Kaufanfrage, Sammlung anbieten): nur SQL. */
final class AnfrageRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param array{name:string, email:?string, telefon:?string, nachricht:string, wunsch:?string} $d
     */
    public function anlegen(string $art, ?int $angebotId, array $d, \DateTimeImmutable $jetzt): int
    {
        $zeit = $jetzt->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO boerse_anfrage (art, angebot_id, name, email, telefon, nachricht, wunsch, einwilligung_am, eingegangen_am) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$art, $angebotId, $d['name'], $d['email'], $d['telefon'], $d['nachricht'], $art === 'sammlung' ? $d['wunsch'] : null, $zeit, $zeit]);
        return (int) $this->pdo->lastInsertId();
    }

    public const STATUS = ['offen', 'in_bearbeitung', 'erledigt'];

    private const SELECT = 'SELECT q.*, a.jahr AS angebot_jahr, a.laufnummer AS angebot_laufnummer, a.bezeichnung AS angebot_bezeichnung '
        . 'FROM boerse_anfrage q LEFT JOIN boerse_angebot a ON a.id = q.angebot_id ';

    /**
     * Anfragen, neueste zuerst, mit Angebotsnummer (angebot_nummer) und Anzahl Fotos.
     *
     * @param ?string $status null = offen und in Bearbeitung, 'alle' = alle, sonst ein Wert aus STATUS
     * @return list<array<string,mixed>>
     */
    public function suche(?string $status): array
    {
        $fotos = "(SELECT COUNT(*) FROM dokument_zuordnung z WHERE z.objekt_typ = 'boerse_anfrage' AND z.objekt_id = q.id) AS fotos_anzahl";
        $sql = str_replace('SELECT q.*,', 'SELECT q.*, ' . $fotos . ',', self::SELECT);
        $werte = [];
        if ($status === null) {
            $sql .= "WHERE q.status IN ('offen', 'in_bearbeitung') ";
        } elseif ($status !== 'alle') {
            if (!in_array($status, self::STATUS, true)) {
                return [];
            }
            $sql .= 'WHERE q.status = ? ';
            $werte[] = $status;
        }
        $st = $this->pdo->prepare($sql . 'ORDER BY q.eingegangen_am DESC, q.id DESC');
        $st->execute($werte);
        return array_map(self::aufbereitet(...), $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Eine Anfrage mit Angebotsnummer und ihren Fotos (fotos: Dokumente der Kategorie foto, älteste zuerst, mit
     * zuordnungen_anzahl).
     *
     * @return ?array<string,mixed>
     */
    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . 'WHERE q.id = ?');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        if ($z === false) {
            return null;
        }
        $z = self::aufbereitet($z);
        $f = $this->pdo->prepare(
            'SELECT d.id, d.titel, d.hat_anzeige, (SELECT COUNT(*) FROM dokument_zuordnung w WHERE w.dokument_id = d.id) AS zuordnungen_anzahl '
            . "FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id WHERE z.objekt_typ = 'boerse_anfrage' AND z.objekt_id = ? "
            . "AND d.kategorie = 'foto' ORDER BY d.hochgeladen_am, d.id"
        );
        $f->execute([$id]);
        $z['fotos'] = $f->fetchAll(\PDO::FETCH_ASSOC);
        return $z;
    }

    public function anzahlOffen(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_anfrage WHERE status = 'offen'")->fetchColumn();
    }

    public function setzeStatus(int $id, string $status, ?int $benutzerId): void
    {
        if (!in_array($status, self::STATUS, true)) {
            throw new \InvalidArgumentException('Unbekannter Status: ' . $status);
        }
        $this->pdo->prepare('UPDATE boerse_anfrage SET status = ?, bearbeitet_von_benutzer_id = ? WHERE id = ?')->execute([$status, $benutzerId, $id]);
    }

    public function setzeMagazinArtikel(int $id, int $artikelId): void
    {
        $this->pdo->prepare('UPDATE boerse_anfrage SET magazin_artikel_id = ? WHERE id = ?')->execute([$artikelId, $id]);
    }

    /**
     * Fotos der Anfrage (älteste zuerst) mit Sperre der Zuordnungen und Dokumente; nur in einer laufenden Transaktion.
     *
     * @return list<array{id:int, hat_anzeige:bool, titel:string}>
     */
    public function sperreFotos(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT d.id, d.hat_anzeige, d.titel FROM dokument_zuordnung z JOIN dokument d ON d.id = z.dokument_id '
            . "WHERE z.objekt_typ = 'boerse_anfrage' AND z.objekt_id = ? AND d.kategorie = 'foto' ORDER BY d.hochgeladen_am, d.id FOR UPDATE"
        );
        $st->execute([$id]);
        return array_map(
            fn(array $z): array => ['id' => (int) $z['id'], 'hat_anzeige' => (int) $z['hat_anzeige'] === 1, 'titel' => (string) $z['titel']],
            $st->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /** @param array<string,mixed> $z @return array<string,mixed> */
    private static function aufbereitet(array $z): array
    {
        $z['angebot_nummer'] = $z['angebot_jahr'] !== null
            ? Angebotsnummer::formatiere((int) $z['angebot_jahr'], (int) $z['angebot_laufnummer'])
            : null;
        return $z;
    }

    /** Sperrt die Anfrage (SELECT … FOR UPDATE); nur in einer laufenden Transaktion. @return ?array<string,mixed> */
    public function sperre(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM boerse_anfrage WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $z = $st->fetch(\PDO::FETCH_ASSOC);
        return $z === false ? null : $z;
    }

    /** @return list<int> Anfragen, die vor $grenze eingegangen sind (aufsteigend) */
    public function eingegangenVor(\DateTimeImmutable $grenze): array
    {
        $st = $this->pdo->prepare('SELECT id FROM boerse_anfrage WHERE eingegangen_am < ? ORDER BY id');
        $st->execute([$grenze->format('Y-m-d H:i:s')]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Löscht die Anfrage samt ihren Protokolleinträgen (Fotos entfernt der AnfrageDienst vorher). */
    public function loesche(int $id): void
    {
        $this->pdo->prepare("DELETE FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage' AND objekt_id = ?")->execute([$id]);
        $this->pdo->prepare('DELETE FROM boerse_anfrage WHERE id = ?')->execute([$id]);
    }
}
