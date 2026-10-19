<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\OneDrive;

use PHPUnit\Framework\TestCase;

/** bin/onedrive-sync.php läuft ohne Web-Anfrage (CLI oder php-cgi im Cronjob) und verweigert sich bei einer Web-Anfrage. */
final class SyncSkriptTest extends TestCase
{
    /** @return array{0:int,1:string,2:string} Exit-Code, Ausgabe, Fehlerausgabe */
    private static function starte(array $env): array
    {
        $skript = dirname(__DIR__, 3) . '/bin/onedrive-sync.php';
        $basis = ['PATH' => (string) getenv('PATH')];
        $p = proc_open([PHP_BINARY, $skript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $basis + $env);
        self::assertIsResource($p);
        $aus = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), $aus, $err];
    }

    public function testMitWebAnfrageNichtsTun(): void
    {
        [$code, $aus, $err] = self::starte(['REQUEST_METHOD' => 'GET']);
        self::assertSame(0, $code);
        self::assertSame('', $aus . $err);
    }

    public function testOhneWebAnfrageWirdAusgefuehrt(): void
    {
        [$code, $aus, $err] = self::starte([]);
        self::assertNotSame('', $aus . $err, 'Skript läuft über die Sperre hinaus');
        if (!is_file(dirname(__DIR__, 3) . '/config.php')) {
            self::assertSame(1, $code);
            self::assertStringContainsString('config.php fehlt.', $err);
        }
    }

    /** Die Skripte in bin/ werden von keinem anderen Test geladen: Syntaxfehler (z. B. ein Kommentar-Ende in einer Cron-Zeile) fielen sonst nicht auf. */
    public function testBinSkripteSindSyntaktischGueltig(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/bin/*.php') ?: [] as $datei) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($datei) . ' 2>&1', $ausgabe, $code);
            self::assertSame(0, $code, basename($datei) . ': ' . implode("\n", $ausgabe));
        }
    }
}
