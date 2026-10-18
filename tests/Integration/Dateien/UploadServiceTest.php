<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Dateien;

use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadFehler;
use Emc\Dateien\UploadService;
use Emc\Tests\Integration\DbTestCase;

final class UploadServiceTest extends DbTestCase
{
    private string $speicher;
    private UploadService $service;
    private DokumentRepository $repo;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->service = $this->neuerService(64 * 1048576);
        $this->repo = new DokumentRepository($this->pdo, $this->speicher);
    }

    protected function tearDown(): void
    {
        self::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    public static function raeumeAuf(string $ordner): void
    {
        if (!is_dir($ordner)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($ordner, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($ordner);
    }

    private function neuerService(int $max): UploadService
    {
        return new UploadService(
            $this->pdo,
            $this->speicher,
            $max,
            new Bildbearbeitung(),
            fn(string $tmp): bool => is_file($tmp),
            fn(string $von, string $nach): bool => copy($von, $nach)
        );
    }

    /** @return array<string,mixed> */
    private function upload(string $fixture, ?string $name = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        copy(dirname(__DIR__, 2) . '/fixtures/dateien/' . $fixture, $tmp);
        return ['name' => $name ?? $fixture, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    /** @return array<string,mixed> */
    private function uploadInhalt(string $inhalt, string $name): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        file_put_contents($tmp, $inhalt);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)];
    }

    private function erwarteFehler(callable $f, string $teil): void
    {
        try {
            $f();
            $this->fail('UploadFehler erwartet');
        } catch (UploadFehler $e) {
            $this->assertStringContainsString($teil, $e->getMessage());
        }
    }

    public function test_php_endung_abgelehnt(): void
    {
        $this->erwarteFehler(fn() => $this->service->speichere($this->uploadInhalt('<?php echo 1;', 'x.php'), 'x', 'sonstiges', null), 'Dateien vom Typ .php sind nicht erlaubt.');
    }

    public function test_doppelte_endung_mit_gefaehrlicher_letzter_wird_abgelehnt(): void
    {
        $this->erwarteFehler(fn() => $this->service->speichere($this->upload('foto_gedreht.jpg', 'x.jpg.php'), 'x', 'foto', null), '.php sind nicht erlaubt');
    }

    public function test_ohne_endung_und_svg_abgelehnt(): void
    {
        $this->erwarteFehler(fn() => $this->service->speichere($this->uploadInhalt('abc', 'README'), 'x', 'sonstiges', null), 'keine Endung');
        $this->erwarteFehler(fn() => $this->service->speichere($this->uploadInhalt('<svg onload=alert(1)>', 'a.svg'), 'x', 'sonstiges', null), '.svg sind nicht erlaubt');
    }

    public function test_getarnte_datei_abgelehnt(): void
    {
        $this->erwarteFehler(fn() => $this->service->speichere($this->upload('getarnt.jpg'), 'x', 'foto', null), 'Der Inhalt der Datei passt nicht zur Endung .jpg.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dokument')->fetchColumn());
    }

    public function test_zu_gross(): void
    {
        $this->erwarteFehler(fn() => $this->neuerService(10)->speichere($this->uploadInhalt(str_repeat('a', 11), 'a.txt'), 'x', 'sonstiges', null), 'zu groß');
    }

    public function test_upload_fehlercodes(): void
    {
        $fehler = fn(int $code) => ['name' => 'a.pdf', 'tmp_name' => '', 'error' => $code, 'size' => 0];
        $this->erwarteFehler(fn() => $this->service->speichere($fehler(UPLOAD_ERR_NO_FILE), '', 'sonstiges', null), 'Es wurde keine Datei ausgewählt.');
        $this->erwarteFehler(fn() => $this->service->speichere($fehler(UPLOAD_ERR_PARTIAL), '', 'sonstiges', null), 'Die Datei wurde nur teilweise hochgeladen. Bitte erneut versuchen.');
        // Die Meldung nennt das tatsächlich wirksame Limit: das kleinere aus Konfiguration und upload_max_filesize.
        $ini = UploadService::iniBytes((string) ini_get('upload_max_filesize'));
        $mb = max(1, intdiv($ini > 0 ? min($ini, 64 * 1048576) : 64 * 1048576, 1048576));
        $this->erwarteFehler(fn() => $this->service->speichere($fehler(UPLOAD_ERR_INI_SIZE), '', 'sonstiges', null), "Die Datei ist zu groß (höchstens $mb MB).");
    }

    public function test_nicht_hochgeladene_datei_wird_abgelehnt(): void
    {
        $service = new UploadService($this->pdo, $this->speicher, 1048576, new Bildbearbeitung(), fn(): bool => false);
        $this->erwarteFehler(fn() => $service->speichere($this->upload('anleitung.pdf'), 'x', 'anleitung', null), 'konnte nicht hochgeladen');
    }

    public function test_produktiv_standard_verlangt_echten_upload(): void
    {
        $service = new UploadService($this->pdo, $this->speicher, 1048576, new Bildbearbeitung());
        $this->erwarteFehler(fn() => $service->speichere($this->upload('anleitung.pdf'), 'x', 'anleitung', null), 'konnte nicht hochgeladen');
    }

    public function test_gedrehtes_handyfoto_wird_aufrecht_verkleinert(): void
    {
        $id = $this->service->speichere($this->upload('foto_gedreht.jpg'), 'Lok', 'foto', null);
        $dok = $this->repo->finde($id);
        [$b, $h] = getimagesize($this->speicher . '/' . $dok['pfad'] . '.vorschau.jpg');
        $this->assertGreaterThan($b, $h);
        $this->assertLessThanOrEqual(400, max($b, $h));
        $this->assertSame(1, (int) $dok['hat_anzeige']);
        $this->assertFileExists($this->speicher . '/' . $dok['pfad'] . '.anzeige.jpg');
    }

    public function test_grosses_foto_wird_auf_1600_und_400_verkleinert(): void
    {
        $im = imagecreatetruecolor(3000, 2000);
        $tmp = tempnam(sys_get_temp_dir(), 'big');
        $this->temp[] = $tmp;
        imagejpeg($im, $tmp);
        $id = $this->service->speichere(['name' => 'gross.JPG', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)], '', 'sonstiges', null);
        $dok = $this->repo->finde($id);
        $this->assertSame('foto', $dok['kategorie']);
        $this->assertSame('gross.JPG', $dok['titel']);
        $this->assertSame('jpg', $dok['endung']);
        $this->assertSame(1600, getimagesize($this->speicher . '/' . $dok['pfad'] . '.anzeige.jpg')[0]);
        $this->assertSame(400, getimagesize($this->speicher . '/' . $dok['pfad'] . '.vorschau.jpg')[0]);
    }

    public function test_gespeicherte_datei_hat_keine_endung(): void
    {
        $id = $this->service->speichere($this->upload('anleitung.pdf'), 'Anleitung', 'anleitung', null);
        $dok = $this->repo->finde($id);
        $this->assertDoesNotMatchRegularExpression('/\.[a-z]+$/', $dok['pfad']);
        $this->assertMatchesRegularExpression('#^dateien/\d{4}/\d{2}/[0-9a-f]{32}$#', $dok['pfad']);
        $this->assertFileExists($this->speicher . '/' . $dok['pfad']);
        $this->assertSame('application/pdf', $dok['mime']);
        $this->assertSame('pdf', $dok['endung']);
        $this->assertSame(0, (int) $dok['hat_anzeige']);
    }

    public function test_loeschen_entfernt_dateien_und_titelbild(): void
    {
        $this->pdo->exec("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) SELECT 'EMC-0001', 'Lok', id FROM spurweite LIMIT 1");
        $fz = (int) $this->pdo->lastInsertId();
        $id = $this->service->speichere($this->upload('foto_gedreht.jpg'), 'Lok', 'foto', null);
        $this->repo->zuordnen($id, 'fahrzeug', $fz);
        $this->repo->setzeTitelbild($fz, $id);
        $pfad = $this->speicher . '/' . $this->repo->finde($id)['pfad'];
        $this->assertCount(1, $this->repo->fuerObjekt('fahrzeug', $fz));
        $this->repo->loeschen($id);
        $this->assertNull($this->repo->finde($id));
        $this->assertFileDoesNotExist($pfad);
        $this->assertFileDoesNotExist($pfad . '.vorschau.jpg');
        $this->assertNull($this->pdo->query("SELECT titelbild_dokument_id FROM fahrzeug WHERE id = $fz")->fetchColumn());
    }

    public function test_zu_wenig_speicher_ergibt_keine_varianten_aber_gueltigen_upload(): void
    {
        $service = new UploadService(
            $this->pdo, $this->speicher, 64 * 1048576, new Bildbearbeitung(fn(): int => 1048576),
            fn(string $t): bool => is_file($t), fn(string $a, string $b): bool => copy($a, $b)
        );
        $id = $service->speichere($this->upload('foto_gedreht.jpg'), '', 'foto', null);
        $dok = $this->repo->finde($id);
        $this->assertSame(0, (int) $dok['hat_anzeige']);
        $this->assertFileExists($this->speicher . '/' . $dok['pfad']);
        $this->assertFileDoesNotExist($this->speicher . '/' . $dok['pfad'] . '.vorschau.jpg');
    }

    public function test_speicherbudget_unbegrenzt_oder_ausreichend_erzeugt_varianten(): void
    {
        $b = new Bildbearbeitung(fn(): ?int => null);
        $ziel = $this->speicher . '/x';
        $this->assertTrue($b->erzeugeVarianten(dirname(__DIR__, 2) . '/fixtures/dateien/foto_gedreht.jpg', $ziel));
        $b = new Bildbearbeitung(fn(): int => 10 ** 9);
        $this->assertTrue($b->erzeugeVarianten(dirname(__DIR__, 2) . '/fixtures/dateien/foto_gedreht.jpg', $ziel));
    }
}
