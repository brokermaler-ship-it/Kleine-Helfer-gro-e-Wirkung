<?php

declare(strict_types=1);

/**
 * Beispieldaten für die Testumgebung anlegen.
 * Aufruf: php bin/demo-daten.php [--zuruecksetzen]
 * Läuft nur, wenn in der config.php 'testumgebung' => true gesetzt ist.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$wurzel = dirname(__DIR__);
require $wurzel . '/vendor/autoload.php';

if (!is_file($wurzel . '/config.php')) {
    fwrite(STDERR, "config.php fehlt.\n");
    exit(1);
}
$config = require $wurzel . '/config.php';

try {
    $app = Emc\Core\App::erzeuge($config);
    (new Emc\Betrieb\DemoDaten($app))->erzeuge(in_array('--zuruecksetzen', $argv, true));
} catch (Emc\Core\DomainFehler $f) {
    fwrite(STDERR, $f->getMessage() . "\n");
    exit(1);
} catch (Throwable $t) {
    fwrite(STDERR, 'Fehler: ' . $t->getMessage() . "\n");
    exit(1);
}
echo "Beispieldaten wurden angelegt. Anmeldung z. B. mitglied@test.emc78.de / Test-EMC78!\n";
