<?php

/**
 * Hilfsprozess für DsgvoLoeschungTest::test_gleichzeitiges_speichern_keine_verwaiste_reparatur_kein_deadlock: führt die
 * DSGVO-Löschung auf einer eigenen Verbindung aus (gleiche Testdatenbank wie die Tests) und gibt „OK“ oder den Fehler aus.
 * Aufruf: php dsgvo_loeschung_prozess.php <personId> <adminBenutzerId> <speicherpfad>
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Auftraege;
use Emc\Personen\DsgvoLoeschung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

try {
    $pdo = DbTestCase::verbindung();
    $dienst = new DsgvoLoeschung($pdo, new DokumentRepository($pdo, (string) $argv[3], new Auftraege($pdo)), new Aenderungsprotokoll($pdo), new FahrzeugRepository($pdo), new Auftraege($pdo), null);
    $dienst->loesche((int) $argv[1], [], (int) $argv[2]);
    echo "OK\n";
} catch (\Throwable $t) {
    fwrite(STDERR, get_class($t) . ': ' . $t->getMessage() . "\n");
    exit(1);
}
