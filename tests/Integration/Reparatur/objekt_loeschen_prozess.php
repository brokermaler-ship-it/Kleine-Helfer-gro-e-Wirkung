<?php

/**
 * Hilfsprozess für ObjektLoeschenTest: löscht ein Fahrzeug bzw. eine Anlage über die Oberfläche (POST …/loeschen) auf
 * einer eigenen Verbindung (gleiche Testdatenbank wie die Tests) und gibt den HTTP-Status oder den Fehler aus.
 * Aufruf: php objekt_loeschen_prozess.php <fahrzeuge|anlagen> <id> <benutzerId>
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Emc\Auth\Auth;
use Emc\Core\App;
use Emc\Core\Instanz;
use Emc\Core\Request;
use Emc\Core\Session;

try {
    $config = [
        'app_name' => 'Test',
        'db_dsn' => getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=emc_test;charset=utf8mb4',
        'db_benutzer' => getenv('TEST_DB_BENUTZER') ?: 'root',
        'db_passwort' => getenv('TEST_DB_PASSWORT') !== false ? (string) getenv('TEST_DB_PASSWORT') : '',
    ];
    $sitzung = ['_csrf' => str_repeat('a', 64), 'benutzer_id' => (int) $argv[3], Auth::INSTANZ_KEY => Instanz::fingerabdruck($config)];
    $antwort = App::erzeuge($config, new Session($sitzung))->handle(new Request('POST', '/' . $argv[1] . '/' . (int) $argv[2] . '/loeschen', [], ['_csrf' => str_repeat('a', 64)]));
    echo $antwort->status, "\n";
} catch (\Throwable $t) {
    fwrite(STDERR, get_class($t) . ': ' . $t->getMessage() . "\n");
    exit(1);
}
