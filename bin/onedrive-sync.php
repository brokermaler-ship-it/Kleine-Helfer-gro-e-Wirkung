<?php

declare(strict_types=1);

/**
 * Überträgt wartende Dateien nach OneDrive (für Cron, empfohlen: alle 15 Minuten, z. B. */15 * * * *).
 * Aufruf: php bin/onedrive-sync.php
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

Emc\Core\App::setzeZeitzone($config);

try {
    $app = Emc\Core\App::erzeuge($config);
    $uebertragung = $app->container()->get(Emc\OneDrive\Uebertragung::class);
    if ($uebertragung === null || !$uebertragung->aktiv()) {
        echo "OneDrive ist nicht verbunden oder nicht eingerichtet; nichts zu tun.\n";
        exit(0);
    }
    $r = $uebertragung->verarbeite(20);
    $z = $app->container()->get(Emc\OneDrive\Auftraege::class)->zaehle();
    echo sprintf("OneDrive: %d übertragen, %d fehlgeschlagen; offen: %d, dauerhaft fehlerhaft: %d\n", $r['erledigt'], $r['fehler'], $z['wartet'], $z['fehler']);
} catch (Throwable $t) {
    fwrite(STDERR, 'OneDrive-Übertragung fehlgeschlagen: ' . $t::class . "\n");
    exit(1);
}
