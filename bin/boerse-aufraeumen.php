<?php

declare(strict_types=1);

/**
 * Löscht Börsenanfragen aus dem öffentlichen Bereich, die vor mehr als 12 Monaten eingegangen sind, samt ihren Fotos,
 * und die gehashten Sperrzeilen der Begrenzung, die älter als 24 Stunden sind (optionaler Cronjob, z. B. täglich).
 * Ohne Cronjob geschieht dasselbe höchstens stündlich beim Aufruf der öffentlichen Seiten.
 * Aufruf: php bin/boerse-aufraeumen.php
 */

// Nur ohne Web-Anfrage ausführen (Muster bin/onedrive-sync.php): der STRATO-Cronjob startet PHP je nach Paket als CLI
// oder als php-cgi, daher entscheidet nicht PHP_SAPI.
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
        echo "Datenbank-Update steht aus (Verwaltung › Update); es wird nichts gelöscht.\n";
        exit(0);
    }
    $n = $app->container()->get(Emc\Boerse\AnfrageDienst::class)->raeumeAuf(new DateTimeImmutable());
    echo $n . " Börsenanfragen gelöscht.\n";
} catch (Throwable $t) {
    fwrite(STDERR, 'Aufräumen der Börsenanfragen fehlgeschlagen: ' . $t::class . "\n");
    exit(1);
}
