<?php

declare(strict_types=1);

namespace Emc\Core;

/**
 * Transaktionen für Schreibungen, die Bestand oder Status ändern (Muster ReparaturDienst::inTransaktion).
 *
 * READ COMMITTED: nach der Sperre des Elternobjekts (SELECT … FOR UPDATE) sieht jede weitere Lesung, was andere
 * Verbindungen inzwischen festgeschrieben haben – mit REPEATABLE READ sähe sie einen veralteten Schnappschuss, und der
 * Bestand könnte doppelt verbraucht werden. Die Stufe gilt nur für diese eine Transaktion.
 */
final class Transaktion
{
    public const SPERRKONFLIKT = 'Gerade arbeitet jemand anderes daran. Bitte noch einmal versuchen.';

    /** MySQL/MariaDB: Wartezeit auf eine Sperre überschritten bzw. Verklemmung erkannt. */
    private const SPERRFEHLER = [1205, 1213];

    /**
     * Führt $arbeit in einer eigenen Transaktion mit READ COMMITTED aus und schreibt fest. Bei jedem Fehler wird
     * zurückgerollt; ein Sperrkonflikt wird zur benutzertauglichen Meldung SPERRKONFLIKT, alles andere weitergeworfen.
     *
     * @template T
     * @param callable(): T $arbeit
     * @return T
     * @throws \LogicException wenn schon eine Transaktion läuft (Programmierfehler)
     * @throws DomainFehler bei Sperrkonflikt (nichts gespeichert)
     */
    public static function readCommitted(\PDO $pdo, callable $arbeit): mixed
    {
        if ($pdo->inTransaction()) {
            throw new \LogicException('Transaktion::readCommitted braucht eine eigene Transaktion.');
        }
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); // gilt nur für die nächste Transaktion
            $pdo->beginTransaction();
            $ergebnis = $arbeit();
            $pdo->commit();
            return $ergebnis;
        } catch (\Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($t instanceof \PDOException && in_array((int) ($t->errorInfo[1] ?? 0), self::SPERRFEHLER, true)) {
                throw new DomainFehler(self::SPERRKONFLIKT, 0, $t);
            }
            throw $t;
        }
    }

    /**
     * Für Methoden, die nur innerhalb der Transaktion eines Aufrufers laufen dürfen.
     *
     * @throws \LogicException wenn keine Transaktion läuft
     */
    public static function verlangeLaufende(\PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Diese Methode muss in einer laufenden Transaktion aufgerufen werden.');
        }
    }
}
