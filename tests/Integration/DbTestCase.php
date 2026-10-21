<?php

declare(strict_types=1);

namespace Emc\Tests\Integration;

use Emc\Auth\Auth;
use Emc\Betrieb\Migrator;
use Emc\Core\Instanz;
use Emc\Core\Database;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase
{
    protected \PDO $pdo;

    /**
     * Zeilen der Tabelle wartungsart direkt nach dem Migrieren (Startbestand aller Migrationen).
     *
     * @var list<array<string,mixed>>
     */
    private static array $wartungsartBestand = [];

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

    /** Verbindung, die für den ganzen Testlauf die Sperre auf die Testdatenbank hält (siehe sperreTestdatenbank). */
    private static ?\PDO $laufSperre = null;

    /**
     * Zwei Testläufe auf derselben Datenbank (z. B. zwei Prüfungen gleichzeitig mit TEST_DB_DSN auf emc_rv) leeren
     * sich gegenseitig die Tabellen; das ergab vereinzelte, nicht nachstellbare Fehler (einmal KennwortHttpTest:
     * „2 Anfragen“ fehlte, weil das Konto mitten im Test verschwunden war). Deshalb hält der erste Testlauf eine
     * benannte Sperre je Datenbank bis zum Ende; ein zweiter bricht sofort mit klarer Meldung ab.
     */
    private static function sperreTestdatenbank(): void
    {
        if (self::$laufSperre !== null) {
            return;
        }
        $pdo = self::verbindung();
        $name = 'emc_testlauf_' . substr((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), 0, 48);
        $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$name]);
        if ((int) $st->fetchColumn() !== 1) {
            throw new \RuntimeException(
                'Die Testdatenbank wird gerade von einem anderen Testlauf benutzt (Sperre „' . $name . '“). '
                . 'Bitte warten oder mit TEST_DB_DSN eine eigene Datenbank wählen.'
            );
        }
        self::$laufSperre = $pdo;
    }

    public static function setUpBeforeClass(): void
    {
        self::sperreTestdatenbank();
        $pdo = self::verbindung();
        self::alleTabellenLoeschen($pdo);
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->ausfuehren();
        self::$wartungsartBestand = $pdo->query('SELECT * FROM wartungsart ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function setUp(): void
    {
        $this->pdo = self::verbindung();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tabellen = self::tabellen($this->pdo);
        foreach ($tabellen as $tabelle) {
            if ($tabelle !== 'schema_migration' && $tabelle !== 'spurweite') {
                $this->pdo->exec('TRUNCATE TABLE `' . $tabelle . '`');
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        if (in_array('wartungsart', $tabellen, true)) {
            self::startbestandWartungsarten($this->pdo);
        }
    }

    /**
     * Stellt den Startbestand der Wartungsarten wieder her, wie er nach dem Migrieren in setUpBeforeClass() war (die
     * Tabelle wird wie alle anderen geleert, damit Änderungen eines Tests nicht in den nächsten durchschlagen). So folgt
     * die Testdatenbank jeder Migration, die den Startbestand ändert, ohne die SQL-Dateien selbst auszuwerten.
     */
    protected static function startbestandWartungsarten(\PDO $pdo): void
    {
        if (self::$wartungsartBestand === []) {
            return;
        }
        $spalten = array_keys(self::$wartungsartBestand[0]);
        $st = $pdo->prepare(
            'INSERT INTO wartungsart (`' . implode('`, `', $spalten) . '`) VALUES (' . implode(', ', array_fill(0, count($spalten), '?')) . ')'
        );
        foreach (self::$wartungsartBestand as $zeile) {
            $st->execute(array_values($zeile));
        }
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

    /**
     * Trägt eine Anmeldung in eine Test-Sitzung ein (wie nach Auth::anmelden für die Testinstanz).
     *
     * @param array<string,mixed> $sitzung
     */
    protected function sitzungAnmelden(array &$sitzung, int $benutzerId): void
    {
        $sitzung['benutzer_id'] = $benutzerId;
        $sitzung[Auth::INSTANZ_KEY] = Instanz::fingerabdruck(self::testConfig());
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
