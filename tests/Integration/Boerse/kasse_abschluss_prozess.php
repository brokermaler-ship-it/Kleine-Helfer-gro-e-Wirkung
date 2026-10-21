<?php

/**
 * Hilfsprozess für KasseDienstTest::test_gleichzeitige_kassen_prozess: schließt einen Warenkorb auf einer eigenen
 * Verbindung ab (gleiche Testdatenbank wie die Tests) – wie eine zweite Kasse auf einem anderen Handy.
 * Aufruf: php kasse_abschluss_prozess.php <boerseId> <benutzerId> <zahlart> <angebotId:preis> [...]
 * Ausgabe: „OK <verkaufId>“ bzw. „KASSE <Meldungen>“ (beides Exit 0); andere Fehler auf STDERR mit Exit 1.
 */

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Emc\Boerse\AngebotRepository;
use Emc\Boerse\KasseDienst;
use Emc\Boerse\KasseFehler;
use Emc\Magazin\MagazinDienst;
use Emc\Magazin\MagazinRepository;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

try {
    $pdo = DbTestCase::verbindung();
    $protokoll = new Aenderungsprotokoll($pdo);
    $angebote = new AngebotRepository($pdo);
    $kasse = new KasseDienst($pdo, $angebote, new MagazinDienst($pdo, new MagazinRepository($pdo), $protokoll, $angebote), $protokoll);
    $positionen = [];
    foreach (array_slice($argv, 4) as $p) {
        [$id, $preis] = explode(':', $p, 2);
        $positionen[] = ['angebot_id' => (int) $id, 'preis' => $preis];
    }
    try {
        $id = $kasse->abschliessen((int) $argv[1], $positionen, (string) $argv[3], (int) $argv[2], new \DateTimeImmutable('2026-11-08 10:15:00'));
        echo 'OK ' . $id . "\n";
    } catch (KasseFehler $e) {
        echo 'KASSE ' . implode(' ', $e->meldungen) . "\n";
    }
} catch (\Throwable $t) {
    fwrite(STDERR, get_class($t) . ': ' . $t->getMessage() . "\n");
    exit(1);
}
