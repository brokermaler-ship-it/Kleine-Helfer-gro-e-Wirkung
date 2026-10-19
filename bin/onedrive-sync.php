<?php

declare(strict_types=1);

/**
 * Überträgt wartende Dateien nach OneDrive (für Cron, empfohlen: alle 15 Minuten, crontab-Zeile siehe README).
 * Aufruf: php bin/onedrive-sync.php
 */

// Nur ohne Web-Anfrage ausführen. Die Datei liegt außerhalb von public/ und ist nicht über den Browser erreichbar;
// der STRATO-Cronjob startet PHP je nach Paket als CLI oder als php-cgi, daher entscheidet nicht PHP_SAPI.
if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}
if (!defined('STDERR')) {
    // php-cgi kennt STDERR nicht.
    define('STDERR', fopen('php://stderr', 'wb'));
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
    if ($app->container()->get(Emc\Betrieb\Migrator::class)->ausstehend() !== []) {
        echo "Datenbank-Update steht aus (Verwaltung › Update); OneDrive ruht bis dahin.\n";
        exit(0);
    }
    $uebertragung = $app->container()->get(Emc\OneDrive\Uebertragung::class);
    if ($uebertragung === null || !$uebertragung->aktiv()) {
        echo "OneDrive ist nicht verbunden oder nicht eingerichtet; nichts zu tun.\n";
        exit(0);
    }
    $uebertragung->setzeZeitbudget(null);
    $r = $uebertragung->verarbeite(20);
    $z = $app->container()->get(Emc\OneDrive\Auftraege::class)->zaehle();
    echo sprintf("OneDrive: %d übertragen, %d fehlgeschlagen; offen: %d, dauerhaft fehlerhaft: %d\n", $r['erledigt'], $r['fehler'], $z['wartet'], $z['fehler']);
} catch (Throwable $t) {
    fwrite(STDERR, 'OneDrive-Übertragung fehlgeschlagen: ' . $t::class . "\n");
    exit(1);
}
