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
