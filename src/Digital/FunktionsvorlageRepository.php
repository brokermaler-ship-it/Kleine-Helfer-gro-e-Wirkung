<?php

declare(strict_types=1);

namespace Emc\Digital;

use Emc\Core\DomainFehler;

final class FunktionsvorlageRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function alle(): array
    {
        return $this->pdo->query(
            'SELECT v.id, v.name, v.decoder_typ, (SELECT COUNT(*) FROM vorlage_funktion f WHERE f.vorlage_id = v.id) AS anzahl
             FROM funktionsvorlage v ORDER BY v.name'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function ausDecoder(int $decoderId, string $name, ?string $decoderTyp): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM funktionsvorlage WHERE name = ?');
        $st->execute([$name]);
        if ((int) $st->fetchColumn() > 0) {
            throw new DomainFehler('Eine Vorlage mit diesem Namen gibt es bereits.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO funktionsvorlage (name, decoder_typ) VALUES (?, ?)')->execute([$name, $decoderTyp]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO vorlage_funktion (vorlage_id, taste, beschreibung, schaltart)
                 SELECT ?, taste, beschreibung, schaltart FROM funktion WHERE decoder_id = ?'
            )->execute([$id, $decoderId]);
            $this->pdo->commit();
            return $id;
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainFehler('Eine Vorlage mit diesem Namen gibt es bereits.', 0, $e);
            }
            throw $e;
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }

    /** Ersetzt die Belegung des Decoders durch die der Vorlage. */
    public function aufDecoderAnwenden(int $vorlageId, int $decoderId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM funktion WHERE decoder_id = ?')->execute([$decoderId]);
            $this->pdo->prepare(
                'INSERT INTO funktion (decoder_id, taste, beschreibung, schaltart)
                 SELECT ?, taste, beschreibung, schaltart FROM vorlage_funktion WHERE vorlage_id = ?'
            )->execute([$decoderId, $vorlageId]);
            $this->pdo->commit();
        } catch (\Throwable $t) {
            $this->pdo->rollBack();
            throw $t;
        }
    }

    public function finde(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM funktionsvorlage WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function loeschen(int $id): void
    {
        $this->pdo->prepare('DELETE FROM funktionsvorlage WHERE id = ?')->execute([$id]);
    }
}
