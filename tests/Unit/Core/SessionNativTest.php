<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\Instanz;
use PHPUnit\Framework\TestCase;

/** Startet die echte PHP-Sitzung in einem eigenen PHP-Prozess (in PHPUnit sind schon Ausgaben erfolgt). */
final class SessionNativTest extends TestCase
{
    private string $wurzel;

    protected function setUp(): void
    {
        $this->wurzel = sys_get_temp_dir() . '/emc_sitzung_' . bin2hex(random_bytes(6));
        mkdir($this->wurzel, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->wurzel, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->wurzel);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function starte(array $config): array
    {
        $skript = $this->wurzel . '/start.php';
        file_put_contents($skript, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$app = Emc\Core\App::erzeuge(' . var_export($config, true) . ');'
            . '$app->container()->get("session");'
            . 'echo json_encode(["name" => session_name(), "pfad" => session_save_path(), "gc" => (int) ini_get("session.gc_maxlifetime"),'
            . '"cookie" => session_get_cookie_params(), "status" => session_status(), "rechte" => fileperms(session_save_path()) & 0777]);');
        $ausgabe = shell_exec(escapeshellarg(PHP_BINARY) . ' -d session.save_handler=files ' . escapeshellarg($skript) . ' 2>&1');
        $daten = json_decode((string) $ausgabe, true);
        $this->assertIsArray($daten, (string) $ausgabe);
        return $daten;
    }

    public function test_eigener_speicherort_name_pfad_und_dauer_je_instanz(): void
    {
        $echt = ['db_dsn' => 'mysql:host=db;dbname=emc78', 'basis_url' => '', 'speicher_pfad' => $this->wurzel . '/echt', 'cookie_secure' => false];
        $test = ['db_dsn' => 'mysql:host=db;dbname=emc78_test', 'basis_url' => '/test', 'speicher_pfad' => $this->wurzel . '/test', 'cookie_secure' => false, 'sitzung_minuten' => 60];

        $a = $this->starte($echt);
        $this->assertSame(PHP_SESSION_ACTIVE, $a['status']);
        $this->assertSame($this->wurzel . '/echt/sitzungen', $a['pfad']);
        $this->assertSame(0700, $a['rechte']);
        $this->assertSame(Instanz::sitzungsName($echt), $a['name']);
        $this->assertSame('/', $a['cookie']['path']);
        $this->assertSame(0, $a['cookie']['lifetime']);
        $this->assertTrue($a['cookie']['httponly']);
        $this->assertSame(28800, $a['gc']);

        $b = $this->starte($test);
        $this->assertSame($this->wurzel . '/test/sitzungen', $b['pfad']);
        $this->assertNotSame($a['name'], $b['name']);
        $this->assertSame('/test', $b['cookie']['path']);
        $this->assertSame(3600, $b['gc']);
    }
}
