<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Core;

use Emc\Core\DomainFehler;
use Emc\Core\Transaktion;
use Emc\Tests\Integration\DbTestCase;

final class TransaktionTest extends DbTestCase
{
    private function anzahl(\PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM einstellung WHERE schluessel LIKE 'tx_%'")->fetchColumn();
    }

    /**
     * READ COMMITTED: eine zweite Lesung in derselben Transaktion sieht, was eine andere Verbindung inzwischen
     * festgeschrieben hat. (@@tx_isolation zeigt auf MariaDB die nur für die nächste Transaktion gesetzte Stufe nicht an,
     * deshalb wird das Verhalten geprüft.) Die Stufe gilt nur für diese eine Transaktion.
     */
    public function test_arbeit_laeuft_mit_read_committed(): void
    {
        $andere = self::verbindung();
        $gesehen = Transaktion::readCommitted($this->pdo, function () use ($andere): array {
            $this->assertTrue($this->pdo->inTransaction());
            $vorher = $this->anzahl($this->pdo);
            $andere->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_fremd', '1')");
            return [$vorher, $this->anzahl($this->pdo)];
        });
        $this->assertSame([0, 1], $gesehen);
        $this->assertFalse($this->pdo->inTransaction());

        // Danach wieder die Standardstufe (REPEATABLE READ): der Schnappschuss bleibt.
        $this->pdo->beginTransaction();
        $vorher = $this->anzahl($this->pdo);
        $andere->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_fremd2', '1')");
        $this->assertSame($vorher, $this->anzahl($this->pdo));
        $this->pdo->commit();
    }

    public function test_ergebnis_und_festschreiben(): void
    {
        $ergebnis = Transaktion::readCommitted($this->pdo, function (): string {
            $this->pdo->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_a', '1')");
            return 'fertig';
        });
        $this->assertSame('fertig', $ergebnis);
        $this->assertSame(1, $this->anzahl(self::verbindung()));
    }

    public function test_ausnahme_rollt_zurueck_und_wird_weitergeworfen(): void
    {
        try {
            Transaktion::readCommitted($this->pdo, function (): void {
                $this->pdo->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_b', '1')");
                throw new DomainFehler('Geht nicht.');
            });
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame('Geht nicht.', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->anzahl($this->pdo));

        try {
            Transaktion::readCommitted($this->pdo, function (): void {
                $this->pdo->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_c', '1')");
                $this->pdo->exec('SELECT * FROM gibt_es_nicht');
            });
            $this->fail('PDOException erwartet');
        } catch (\PDOException $e) {
            $this->assertSame('42S02', $e->getCode());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->anzahl($this->pdo));
    }

    public function test_verschachtelt_ist_ein_programmierfehler(): void
    {
        $this->pdo->beginTransaction();
        try {
            Transaktion::readCommitted($this->pdo, fn() => null);
            $this->fail('LogicException erwartet');
        } catch (\LogicException) {
            $this->assertTrue($this->pdo->inTransaction(), 'die fremde Transaktion bleibt unberührt');
        } finally {
            $this->pdo->rollBack();
        }

        $this->expectException(\LogicException::class);
        Transaktion::readCommitted($this->pdo, fn() => Transaktion::readCommitted($this->pdo, fn() => null));
    }

    public function test_verlange_laufende(): void
    {
        Transaktion::readCommitted($this->pdo, function (): void {
            Transaktion::verlangeLaufende($this->pdo);
        });
        $this->expectException(\LogicException::class);
        Transaktion::verlangeLaufende($this->pdo);
    }

    public function test_sperrkonflikt_wird_zur_meldung(): void
    {
        $this->pdo->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_gesperrt', '1')");
        $andere = self::verbindung();
        $andere->beginTransaction();
        $andere->query("SELECT * FROM einstellung WHERE schluessel = 'tx_gesperrt' FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            Transaktion::readCommitted($this->pdo, function (): void {
                $this->pdo->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_neu', '1')");
                $this->pdo->query("SELECT * FROM einstellung WHERE schluessel = 'tx_gesperrt' FOR UPDATE")->fetchAll();
            });
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame(Transaktion::SPERRKONFLIKT, $e->getMessage());
            $this->assertSame('Gerade arbeitet jemand anderes daran. Bitte noch einmal versuchen.', $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
        } finally {
            $andere->rollBack();
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, $this->anzahl($this->pdo), 'tx_neu wurde nicht gespeichert');
    }

    /** Verklemmung (1213) wird wie die Wartezeit (1205) gemeldet; andere Datenbankfehler bleiben unverändert. */
    public function test_verklemmung_wird_zur_meldung(): void
    {
        $fehler = new \PDOException('Deadlock found when trying to get lock', 40001);
        $fehler->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        try {
            Transaktion::readCommitted($this->pdo, function () use ($fehler): void {
                $this->pdo->exec("INSERT INTO einstellung (schluessel, wert) VALUES ('tx_d', '1')");
                throw $fehler;
            });
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame(Transaktion::SPERRKONFLIKT, $e->getMessage());
            $this->assertSame($fehler, $e->getPrevious());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->anzahl($this->pdo));
    }
}
