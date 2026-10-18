<?php

declare(strict_types=1);

namespace Emc\Tests\Integration;

use Emc\Betrieb\Migrator;
use Emc\Core\Database;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected \PDO $pdo;

    public static function verbindung(): \PDO
    {
        return Database::verbinde(
            getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=emc_test;charset=utf8mb4',
            getenv('TEST_DB_BENUTZER') ?: 'root',
            getenv('TEST_DB_PASSWORT') !== false ? (string) getenv('TEST_DB_PASSWORT') : ''
        );
    }

    public static function alleTabellenLoeschen(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::tabellen($pdo) as $tabelle) {
            $pdo->exec('DROP TABLE `' . $tabelle . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return list<string> */
    private static function tabellen(\PDO $pdo): array
    {
        return array_map('strval', $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function setUpBeforeClass(): void
    {
        $pdo = self::verbindung();
        self::alleTabellenLoeschen($pdo);
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->ausfuehren();
    }

    protected function setUp(): void
    {
        $this->pdo = self::verbindung();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::tabellen($this->pdo) as $tabelle) {
            if ($tabelle !== 'schema_migration' && $tabelle !== 'spurweite') {
                $this->pdo->exec('TRUNCATE TABLE `' . $tabelle . '`');
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
