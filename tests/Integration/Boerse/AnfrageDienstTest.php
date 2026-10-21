<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Betrieb\Einstellungen;
use Emc\Boerse\AnfrageDienst;
use Emc\Boerse\AnfrageRepository;
use Emc\Boerse\Spamschutz;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\Dateien\UploadServiceTest;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\ExifJpeg;

/** Anfragen entgegennehmen (Begrenzung, Fotos ohne Metadaten) und nach 12 Monaten samt Fotos löschen. */
final class AnfrageDienstTest extends DbTestCase
{
    private string $speicher;
    private AnfrageDienst $dienst;
    private DokumentRepository $dok;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->speicher = sys_get_temp_dir() . '/emc_test_' . bin2hex(random_bytes(6));
        mkdir($this->speicher, 0750, true);
        $this->dok = new DokumentRepository($this->pdo, $this->speicher);
        $upload = new UploadService($this->pdo, $this->speicher, 8 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b));
        $this->dienst = new AnfrageDienst(
            $this->pdo,
            new AnfrageRepository($this->pdo),
            new Spamschutz($this->pdo, new Einstellungen($this->pdo)),
            $upload,
            $this->dok,
            new Aenderungsprotokoll($this->pdo),
            new \Emc\Magazin\MagazinDienst($this->pdo, new \Emc\Magazin\MagazinRepository($this->pdo), new Aenderungsprotokoll($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        UploadServiceTest::raeumeAuf($this->speicher);
        foreach ($this->temp as $t) {
            @unlink($t);
        }
    }

    /** @return array<string,mixed> */
    private function foto(string $inhalt, string $name): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        $this->temp[] = $tmp;
        file_put_contents($tmp, $inhalt);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($inhalt)];
    }

    /** @return array<string,mixed> */
    private function daten(array $mehr = []): array
    {
        return $mehr + ['name' => 'Erika Beispiel', 'email' => 'erika@test.emc78.de', 'telefon' => null, 'nachricht' => 'Kiste mit Spur-N-Wagen', 'wunsch' => 'spenden'];
    }

    private function zahl(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    public function test_sammlung_mit_fotos_ohne_metadaten(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        $pdf = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/dateien/anleitung.pdf');
        $ergebnis = $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [
            $this->foto(ExifJpeg::erzeuge(), 'IMG_2026 Wohnzimmer Familie Beispiel.jpg'),
            $this->foto($pdf, 'liste.pdf'),
            $this->foto(ExifJpeg::erzeuge(400, 300, 1), 'zweites.jpeg'),
        ], '203.0.113.7', $jetzt);
        $this->assertSame('gespeichert', $ergebnis);
        $this->assertSame(1, $this->dienst->abgelehnteFotos());
        $anfrage = $this->pdo->query('SELECT * FROM boerse_anfrage')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('sammlung', $anfrage['art']);
        $this->assertSame('offen', $anfrage['status']);
        $this->assertSame('spenden', $anfrage['wunsch']);
        $this->assertSame('2026-10-21 12:00:00', $anfrage['eingegangen_am']);
        $this->assertSame('2026-10-21 12:00:00', $anfrage['einwilligung_am']);
        $doks = $this->pdo->query("SELECT d.* FROM dokument d JOIN dokument_zuordnung z ON z.dokument_id = d.id WHERE z.objekt_typ = 'boerse_anfrage' ORDER BY d.id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['foto-1.jpg', 'foto-2.jpg'], array_column($doks, 'original_name'));
        $this->assertSame(['foto-1.jpg', 'foto-2.jpg'], array_column($doks, 'titel'));
        $this->assertSame(['jpg', 'jpg'], array_column($doks, 'endung'));
        $this->assertSame(['image/jpeg', 'image/jpeg'], array_column($doks, 'mime'));
        $this->assertSame([null, null], array_column($doks, 'hochgeladen_von'));
        $this->assertSame(2, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'boerse_anfrage'"), 'nur der Anfrage zugeordnet');
        foreach ($doks as $d) {
            $pfad = $this->speicher . '/' . $d['pfad'];
            $this->assertFileExists($pfad);
            $exif = @exif_read_data($pfad);
            $this->assertFalse(is_array($exif) && isset($exif['GPSLatitude']), 'ohne GPS');
            $this->assertStringNotContainsString(ExifJpeg::KAMERA, (string) file_get_contents($pfad));
            $this->assertSame((int) filesize($pfad), (int) $d['groesse']);
            $this->assertSame(1, (int) $d['hat_anzeige']);
            $this->assertFileExists($pfad . '.anzeige.jpg');
        }
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM onedrive_auftrag'), 'kein OneDrive');
        // Protokoll ohne Personendaten
        $protokoll = (string) $this->pdo->query("SELECT CONCAT(aktion, ' ', COALESCE(aenderungen, '')) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage'")->fetchColumn();
        $this->assertStringStartsWith('angelegt', $protokoll);
        foreach (['Erika', 'Beispiel', 'erika@', 'Spur-N', '203.0'] as $verboten) {
            $this->assertStringNotContainsString($verboten, $protokoll);
        }
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage_sperre'));
    }

    public function test_begrenzung(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame('gespeichert', $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.7', $jetzt->modify("+$i minutes")));
        }
        $this->assertSame('begrenzt', $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.7', $jetzt->modify('+10 minutes')));
        $this->assertSame(5, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $this->assertSame(5, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage_sperre'), 'abgelehnte Anfrage nicht gezählt');
        $this->assertSame('gespeichert', $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.9', $jetzt->modify('+10 minutes')));
        $this->assertSame('gespeichert', $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.7', $jetzt->modify('+62 minutes')));
    }

    /** Zählen und Speichern laufen nacheinander: die Zeile des Salzes ist gesperrt (sonst überschritten parallele Anfragen die Grenze). */
    public function test_begrenzung_unter_sperre(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.7', $jetzt); // legt das Salz an
        $zweite = self::verbindung();
        $zweite->beginTransaction();
        $zweite->query("SELECT wert FROM einstellung WHERE schluessel = 'boerse_ip_salz' FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.7', $jetzt);
            $this->fail('Sperrkonflikt erwartet');
        } catch (\Emc\Core\DomainFehler $e) {
            $this->assertSame(\Emc\Core\Transaktion::SPERRKONFLIKT, $e->getMessage());
        } finally {
            $zweite->rollBack();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage_sperre'));
    }

    public function test_loeschung_nach_12_monaten(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [$this->foto(ExifJpeg::erzeuge(), 'a.jpg')], '203.0.113.7', $jetzt->modify('-12 months -1 day'));
        $alt = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [$this->foto(ExifJpeg::erzeuge(), 'b.jpg'), $this->foto(ExifJpeg::erzeuge(), 'c.jpg')], '203.0.113.7', $jetzt->modify('-12 months -2 days'));
        $altMitMagazin = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [$this->foto(ExifJpeg::erzeuge(), 'd.jpg')], '203.0.113.7', $jetzt->modify('-11 months'));
        $jung = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');

        $altesFoto = $this->dok->idsFuerObjekt('boerse_anfrage', $alt)[0];
        $altPfad = $this->speicher . '/' . $this->dok->finde($altesFoto)['pfad'];
        // Ein Foto der zweiten alten Anfrage wurde (Task 8) ins Magazin übernommen: es bleibt am Artikel.
        [$geteilt, $nurAnfrage] = $this->dok->idsFuerObjekt('boerse_anfrage', $altMitMagazin);
        $this->pdo->prepare("INSERT INTO magazin_artikel (bezeichnung, kategorie_id) SELECT 'Spur-N-Wagen', id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->execute();
        $artikel = (int) $this->pdo->lastInsertId();
        $this->dok->zuordnen($geteilt, 'magazin_artikel', $artikel);
        $nurPfad = $this->speicher . '/' . $this->dok->finde($nurAnfrage)['pfad'];
        $this->assertSame(3, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage'"));
        // Sperrzeilen: eine von vor 25 h, eine von vor 1 h
        $spam = new Spamschutz($this->pdo, new Einstellungen($this->pdo));
        $this->pdo->exec('DELETE FROM boerse_anfrage_sperre');
        $spam->zaehle($spam->ipHash('203.0.113.7'), $jetzt->modify('-25 hours'));
        $spam->zaehle($spam->ipHash('203.0.113.7'), $jetzt->modify('-1 hour'));

        $this->assertSame(2, $this->dienst->raeumeAuf($jetzt));

        $this->assertSame([$jung], array_map('intval', $this->pdo->query('SELECT id FROM boerse_anfrage')->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertNull($this->dok->finde($altesFoto));
        $this->assertFileDoesNotExist($altPfad);
        $this->assertFileDoesNotExist($altPfad . '.anzeige.jpg');
        $this->assertFileDoesNotExist($altPfad . '.vorschau.jpg');
        $this->assertNull($this->dok->finde($nurAnfrage));
        $this->assertFileDoesNotExist($nurPfad);
        $this->assertNotNull($this->dok->finde($geteilt), 'Foto am Magazinartikel bleibt');
        $this->assertFileExists($this->speicher . '/' . $this->dok->finde($geteilt)['pfad']);
        $this->assertSame(['magazin_artikel'], array_column($this->dok->zuordnungen($geteilt), 'objekt_typ'));
        $this->assertSame(1, count($this->dok->idsFuerObjekt('boerse_anfrage', $jung)));
        $this->assertSame([$jung], array_map('intval', $this->pdo->query("SELECT objekt_id FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage'")->fetchAll(\PDO::FETCH_COLUMN)), 'Protokoll der gelöschten Anfragen entfernt');
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage_sperre'), 'Sperrzeile älter als 24 h entfernt');
    }

    public function test_hoechstens_stuendlich(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        $this->dienst->raeumeAufHoechstensStuendlich($jetzt);
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [], '203.0.113.7', $jetzt->modify('-13 months'));
        $this->dienst->raeumeAufHoechstensStuendlich($jetzt->modify('+30 minutes'));
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'), 'zweiter Aufruf in derselben Stunde räumt nicht auf');
        $this->dienst->raeumeAufHoechstensStuendlich($jetzt->modify('+61 minutes'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
    }

    public function test_loesche_von_hand(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [$this->foto(ExifJpeg::erzeuge(), 'a.jpg')], '203.0.113.7', $jetzt);
        $id = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
        $foto = $this->dok->idsFuerObjekt('boerse_anfrage', $id)[0];
        $this->dienst->loesche($id, null);
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM boerse_anfrage'));
        $this->assertNull($this->dok->finde($foto));
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} Artikel und Eingang für die Übernahme */
    private function uebernahme(): array
    {
        $kategorie = $this->zahl("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'");
        return [
            ['bezeichnung' => 'Kiste', 'kategorie_id' => $kategorie, 'zustand' => 'gebraucht', 'lagerort' => null],
            ['datum' => '2026-10-21', 'menge' => 1, 'herkunft' => 'spende', 'freitext' => 'Anfrage über die Webseite (Nr. 1)', 'betrag' => null],
        ];
    }

    /** Übernahme wartet auf die Sperre der Anfrage (z. B. gleichzeitiges Löschen): Sperrkonflikt, nichts gespeichert. */
    public function test_uebernahme_unter_sperre(): void
    {
        $jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [$this->foto(ExifJpeg::erzeuge(), 'a.jpg')], '203.0.113.7', $jetzt);
        $id = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
        [$artikel, $eingang] = $this->uebernahme();
        $zweite = self::verbindung();
        $zweite->beginTransaction();
        $zweite->query("SELECT id FROM boerse_anfrage WHERE id = $id FOR UPDATE")->fetchAll();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->dienst->uebernimmInsMagazin($id, $artikel, $eingang, null);
            $this->fail('Sperrkonflikt erwartet');
        } catch (\Emc\Core\DomainFehler $e) {
            $this->assertSame(\Emc\Core\Transaktion::SPERRKONFLIKT, $e->getMessage());
        } finally {
            $zweite->rollBack();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_buchung'));
        $this->assertSame('offen', (string) $this->pdo->query("SELECT status FROM boerse_anfrage WHERE id = $id")->fetchColumn());

        $artikelId = $this->dienst->uebernimmInsMagazin($id, $artikel, $eingang, null);
        $this->assertSame(1, $this->zahl("SELECT SUM(menge) FROM magazin_buchung WHERE artikel_id = $artikelId"));
        try {
            $this->dienst->uebernimmInsMagazin($id, $artikel, $eingang, null);
            $this->fail('schon übernommen');
        } catch (\Emc\Core\DomainFehler $e) {
            $this->assertSame(AnfrageDienst::SCHON_UEBERNOMMEN, $e->getMessage());
        }
        $this->assertSame(1, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
    }

    /** Ein Fehler beim Anlegen des Artikels lässt die Anfrage unverändert (eine Transaktion). */
    public function test_uebernahme_fehler_laesst_anfrage_unveraendert(): void
    {
        $this->dienst->nimmEntgegen('sammlung', null, $this->daten(), [$this->foto(ExifJpeg::erzeuge(), 'a.jpg')], '203.0.113.7', new \DateTimeImmutable('2026-10-21 12:00:00'));
        $id = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
        [$artikel, $eingang] = $this->uebernahme();
        $eingang['menge'] = 0;
        try {
            $this->dienst->uebernimmInsMagazin($id, $artikel, $eingang, null);
            $this->fail('Fehler erwartet');
        } catch (\Emc\Core\DomainFehler) {
        }
        $this->assertSame(0, $this->zahl('SELECT COUNT(*) FROM magazin_artikel'));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM dokument_zuordnung WHERE objekt_typ = 'magazin_artikel'"));
        $this->assertSame(0, $this->zahl("SELECT COUNT(*) FROM aenderungsprotokoll WHERE objekt_typ = 'boerse_anfrage' AND aktion = 'geaendert'"));
        $this->assertNull($this->pdo->query("SELECT magazin_artikel_id FROM boerse_anfrage WHERE id = $id")->fetchColumn());

        $kauf = $this->dienst->nimmEntgegen('kaufanfrage', null, $this->daten(['wunsch' => null]), [], '203.0.113.8', new \DateTimeImmutable('2026-10-21 12:00:00'));
        $this->assertSame('gespeichert', $kauf);
        $kaufId = $this->zahl('SELECT MAX(id) FROM boerse_anfrage');
        $this->expectExceptionMessage(AnfrageDienst::NUR_SAMMLUNG);
        $this->dienst->uebernimmInsMagazin($kaufId, $artikel, ['menge' => 1] + $eingang, null);
    }
}
