<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Tests\Integration\DbTestCase;

/** Die Testbasis stellt vor jedem Test den Startbestand der Wartungsarten wieder her – so, wie die Migrationen ihn anlegen. */
final class TestdatenbankTest extends DbTestCase
{
    public function test_startbestand_wartungsarten_wird_wiederhergestellt(): void
    {
        $vorher = $this->pdo->query('SELECT * FROM wartungsart ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(8, $vorher);
        self::assertSame(range(1, 8), array_map('intval', array_column($vorher, 'id')));

        $this->pdo->exec("UPDATE wartungsart SET name = 'Umbenannt; mit Semikolon', aktiv = 0 WHERE id = 1");
        $this->pdo->exec("INSERT INTO wartungsart (name, gilt_fuer, intervall_monate) VALUES ('Zusätzlich', 'anlage', 1)");
        $this->setUp();

        self::assertSame($vorher, $this->pdo->query('SELECT * FROM wartungsart ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        // Neue Zeilen beginnen wieder nach dem Startbestand.
        $this->pdo->exec("INSERT INTO wartungsart (name, gilt_fuer, intervall_monate) VALUES ('Neu', 'anlage', 1)");
        self::assertSame(9, (int) $this->pdo->lastInsertId());
    }
}
