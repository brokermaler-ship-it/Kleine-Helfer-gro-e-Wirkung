<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Betrieb;

use Emc\Betrieb\Einstellungen;
use Emc\Betrieb\Sicherung;
use Emc\Core\Database;
use Emc\Tests\Integration\DbTestCase;

final class SicherungTest extends DbTestCase
{
    private const ZIEL_DB = 'emc_test_wiederherstellung';

    private string $speicher;
    private string $zip;
    private Sicherung $sicherung;
    private Einstellungen $einstellungen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_sich_' . bin2hex(random_bytes(6));
        mkdir($this->speicher . '/dateien/2026/01', 0750, true);
        $this->zip = sys_get_temp_dir() . '/emc_sich_' . bin2hex(random_bytes(6)) . '.zip';
        $this->einstellungen = new Einstellungen($this->pdo);
        $this->sicherung = new Sicherung($this->pdo, $this->speicher, $this->einstellungen);
    }

    protected function tearDown(): void
    {
        @unlink($this->zip);
        $this->loescheVerzeichnis($this->speicher);
        $this->pdo->exec('DROP DATABASE IF EXISTS ' . self::ZIEL_DB);
    }

    private function loescheVerzeichnis(string $pfad): void
    {
        if (!is_dir($pfad)) {
            return;
        }
        foreach (array_diff(scandir($pfad) ?: [], ['.', '..']) as $eintrag) {
            is_dir("$pfad/$eintrag") ? $this->loescheVerzeichnis("$pfad/$eintrag") : unlink("$pfad/$eintrag");
        }
        rmdir($pfad);
    }

    private function datenAnlegen(): void
    {
        $this->pdo->exec("INSERT INTO person (vorname, nachname, notiz) VALUES ('Anna', 'Bär', \"Zeile1;\nZeile2\r\n-- kein Kommentar;\n\")");
        $spur = (int) $this->pdo->query('SELECT id FROM spurweite ORDER BY id LIMIT 1')->fetchColumn();
        $ins = $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, notiz) VALUES (?, ?, ?, ?)');
        $ins->execute(['EMC-0001', 'Köf „Rangier“ II', $spur, null]);
        $fz = (int) $this->pdo->lastInsertId();
        $ins->execute(['EMC-0002', "O'Brien \\ \"Lok\"", $spur, "a;\nb"]);
        $this->pdo->prepare("INSERT INTO decoder (fahrzeug_id, bezeichnung, protokoll, adresse) VALUES (?, 'Motor', 'dcc', 3)")->execute([$fz]);
        file_put_contents($this->speicher . '/dateien/2026/01/abc.bin', "binaer\0\xFF\n;\n");
        $this->pdo->exec("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad) VALUES ('t', 'sonstiges', 'a.pdf', 'pdf', 'application/pdf', 3, 'dateien/2026/01/abc.bin')");
    }

    public function test_sicherung_laesst_sich_wiederherstellen(): void
    {
        $this->datenAnlegen();
        $vorher = (string) $this->pdo->query("SELECT geaendert_am FROM fahrzeug WHERE inventarnummer = 'EMC-0001'")->fetchColumn();

        $this->sicherung->erstelle($this->zip);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zip));
        $this->assertNotFalse($zip->locateName('datenbank.sql'));
        $this->assertNotFalse($zip->locateName('info.txt'));
        $this->assertSame("binaer\0\xFF\n;\n", $zip->getFromName('dateien/2026/01/abc.bin'));
        $this->assertStringContainsString('0.1.0', (string) $zip->getFromName('info.txt'));
        $this->assertStringContainsString('002_spurweiten_start', (string) $zip->getFromName('info.txt'));
        $sql = (string) $zip->getFromName('datenbank.sql');
        $zip->close();
        $this->assertStringStartsWith("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;", $sql);
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS=1;\n", $sql);

        $this->assertNotNull($this->einstellungen->hole('letzte_sicherung'));

        $this->pdo->exec('DROP DATABASE IF EXISTS ' . self::ZIEL_DB);
        $this->pdo->exec('CREATE DATABASE IF NOT EXISTS ' . self::ZIEL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $ziel = Database::verbinde(
            'mysql:host=127.0.0.1;dbname=' . self::ZIEL_DB . ';charset=utf8mb4',
            getenv('TEST_DB_BENUTZER') ?: 'root',
            getenv('TEST_DB_PASSWORT') !== false ? (string) getenv('TEST_DB_PASSWORT') : ''
        );
        $anweisungen = $this->zerlege($sql);
        foreach ($anweisungen as $a) {
            $ziel->exec($a);
        }

        foreach (['fahrzeug' => 2, 'decoder' => 1, 'dokument' => 1, 'person' => 1, 'spurweite' => 9] as $tabelle => $anzahl) {
            $this->assertSame($anzahl, (int) $ziel->query("SELECT COUNT(*) FROM `$tabelle`")->fetchColumn(), $tabelle);
        }
        $this->assertSame('Köf „Rangier“ II', $ziel->query("SELECT bezeichnung FROM fahrzeug WHERE inventarnummer = 'EMC-0001'")->fetchColumn());
        $this->assertSame("O'Brien \\ \"Lok\"", $ziel->query("SELECT bezeichnung FROM fahrzeug WHERE inventarnummer = 'EMC-0002'")->fetchColumn());
        $this->assertSame("a;\nb", $ziel->query("SELECT notiz FROM fahrzeug WHERE inventarnummer = 'EMC-0002'")->fetchColumn());
        $this->assertNull($ziel->query("SELECT notiz FROM fahrzeug WHERE inventarnummer = 'EMC-0001'")->fetchColumn());
        $this->assertSame("Zeile1;\nZeile2\r\n-- kein Kommentar;\n", $ziel->query('SELECT notiz FROM person')->fetchColumn());
        $this->assertSame($vorher, (string) $ziel->query("SELECT geaendert_am FROM fahrzeug WHERE inventarnummer = 'EMC-0001'")->fetchColumn());
    }

    public function test_grosse_tabelle_wird_in_bloecken_gesichert(): void
    {
        $spur = (int) $this->pdo->query('SELECT id FROM spurweite ORDER BY id LIMIT 1')->fetchColumn();
        $ins = $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES (?, ?, ?)');
        for ($i = 1; $i <= 250; $i++) {
            $ins->execute([sprintf('EMC-%04d', $i), "Lok $i", $spur]);
        }
        $this->sicherung->erstelle($this->zip);
        $zip = new \ZipArchive();
        $zip->open($this->zip);
        $sql = (string) $zip->getFromName('datenbank.sql');
        $this->assertSame(3, preg_match_all('/^INSERT INTO `fahrzeug`/m', $sql));
        $this->assertSame(0, $this->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) ? 0 : 1, 'Puffermodus muss wiederhergestellt sein');
    }

    /** @return list<string> */
    private function zerlege(string $sql): array
    {
        $ergebnis = [];
        $aktuell = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $zeile) {
            if (str_starts_with(ltrim($zeile), '--')) {
                continue;
            }
            $aktuell .= $zeile . "\n";
            if (str_ends_with(rtrim($zeile), ';')) {
                $ergebnis[] = trim($aktuell);
                $aktuell = '';
            }
        }
        return $ergebnis;
    }

    public function test_ueberfaellig(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-18 12:00:00');
        $this->assertTrue($this->sicherung->istUeberfaellig($jetzt));
        $this->einstellungen->setze('letzte_sicherung', $jetzt->modify('-29 days')->format(\DATE_ATOM));
        $this->assertFalse($this->sicherung->istUeberfaellig($jetzt));
        $this->einstellungen->setze('letzte_sicherung', $jetzt->modify('-31 days')->format(\DATE_ATOM));
        $this->assertTrue($this->sicherung->istUeberfaellig($jetzt));
        $this->einstellungen->setze('letzte_sicherung', 'kaputt');
        $this->assertTrue($this->sicherung->istUeberfaellig($jetzt));
    }
}
