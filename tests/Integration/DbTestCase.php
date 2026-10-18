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

    /** @return array<string,mixed> */
    protected static function testConfig(): array
    {
        return [
            'app_name' => 'Test',
            'db_dsn' => getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=emc_test;charset=utf8mb4',
            'db_benutzer' => getenv('TEST_DB_BENUTZER') ?: 'root',
            'db_passwort' => getenv('TEST_DB_PASSWORT') !== false ? (string) getenv('TEST_DB_PASSWORT') : '',
        ];
    }

    /** Legt Person und Benutzer an und liefert die Benutzer-ID. */
    protected function legeBenutzerAn(
        string $email,
        string $passwort = 'geheim-passwort-1',
        string $rolle = 'mitglied',
        bool $aktiv = true,
        bool $mussAendern = false
    ): int {
        $this->pdo->prepare('INSERT INTO person (vorname, nachname, email) VALUES (?, ?, ?)')
            ->execute(['Max', 'Muster', $email]);
        $personId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO benutzer (person_id, email, passwort_hash, rolle, aktiv, muss_passwort_aendern) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$personId, strtolower($email), password_hash($passwort, PASSWORD_DEFAULT), $rolle, $aktiv ? 1 : 0, $mussAendern ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }
}
