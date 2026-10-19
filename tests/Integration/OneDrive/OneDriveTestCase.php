<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\Betrieb\Einstellungen;
use Emc\Core\Tresor;
use Emc\Dateien\DokumentRepository;
use Emc\OneDrive\Auftraege;
use Emc\OneDrive\Graph;
use Emc\OneDrive\OAuth;
use Emc\OneDrive\TokenSpeicher;
use Emc\OneDrive\Uebertragung;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\FakeHttpClient;

abstract class OneDriveTestCase extends DbTestCase
{
    protected FakeHttpClient $http;
    protected TokenSpeicher $tokens;
    protected Einstellungen $einstellungen;
    protected string $speicher;
    private string|false $altesLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->altesLog = ini_set('error_log', '/dev/null');
        $this->speicher = sys_get_temp_dir() . '/emc_od_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->http = new FakeHttpClient();
        $this->einstellungen = new Einstellungen($this->pdo);
        $this->tokens = new TokenSpeicher($this->einstellungen, new Tresor(base64_encode(str_repeat('k', 32))));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->altesLog === false ? '' : $this->altesLog);
        if (is_dir($this->speicher)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->speicher, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->speicher);
        }
    }

    protected function verbinde(bool $mitZiel = true): void
    {
        $this->tokens->speichere(['refresh_token' => 'RT', 'access_token' => 'AT', 'ablauf' => time() + 3600]);
        if ($mitZiel) {
            $this->einstellungen->setze('onedrive_ziel', json_encode(['drive_id' => 'D1', 'item_id' => 'ROOT', 'name' => 'EMC']));
        }
    }

    protected function uebertragung(bool $behalten = false): Uebertragung
    {
        $graph = new Graph($this->http, new OAuth($this->http, 'cid', 'sec', 'https://x.de/r'), $this->tokens, 20);
        return new Uebertragung(
            new Auftraege($this->pdo),
            $graph,
            $this->tokens,
            $this->einstellungen,
            new DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo)),
            $this->pdo,
            $this->speicher,
            $behalten
        );
    }

    /** Legt Dokument samt Datei im Speicher an. */
    protected function dokument(string $inhalt = 'PDFDATEN', string $endung = 'pdf', ?int $fahrzeugId = null): int
    {
        $rel = 'dateien/2026/01/' . bin2hex(random_bytes(8));
        if (!is_dir($this->speicher . '/dateien/2026/01')) {
            mkdir($this->speicher . '/dateien/2026/01', 0750, true);
        }
        file_put_contents($this->speicher . '/' . $rel, $inhalt);
        $this->pdo->prepare("INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad) VALUES ('Anleitung', 'anleitung', ?, ?, 'application/pdf', ?, ?)")
            ->execute(['Anleitung.' . $endung, $endung, strlen($inhalt), $rel]);
        $id = (int) $this->pdo->lastInsertId();
        if ($fahrzeugId !== null) {
            $this->pdo->prepare("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES (?, 'fahrzeug', ?)")->execute([$id, $fahrzeugId]);
        }
        return $id;
    }

    protected function fahrzeug(): int
    {
        $this->pdo->exec("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) SELECT 'EMC-0001', 'V 200 060', id FROM spurweite LIMIT 1");
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    protected function dok(int $id): array
    {
        return (new DokumentRepository($this->pdo, $this->speicher))->finde($id) ?? [];
    }
}
