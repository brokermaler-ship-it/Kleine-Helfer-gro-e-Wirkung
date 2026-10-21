<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\Boerse\AnfrageDienst;
use Emc\Boerse\AnfrageRepository;
use Emc\Boerse\Spamschutz;
use Emc\Dateien\Bildbearbeitung;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadService;
use Emc\OneDrive\Auftraege;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * Fotos einer Börsenanfrage gehen nie nach OneDrive. Wird die Anfrage gelöscht und hängt ein Foto weiter an einem
 * Magazinartikel (übernommene Sammlung), ist es ein gewöhnliches Artikelfoto: dann wird es zum Hochladen vorgemerkt.
 */
final class AnfrageLoeschenOneDriveTest extends OneDriveTestCase
{
    private function dienst(): AnfrageDienst
    {
        $dok = new DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo));
        return new AnfrageDienst(
            $this->pdo,
            new AnfrageRepository($this->pdo),
            new Spamschutz($this->pdo, $this->einstellungen),
            new UploadService($this->pdo, $this->speicher, 8 * 1048576, new Bildbearbeitung(), fn($t) => is_file($t), fn($a, $b) => copy($a, $b)),
            $dok,
            new Aenderungsprotokoll($this->pdo),
            null,
            fn() => $this->uebertragung()
        );
    }

    /** @return array{0:int, 1:int, 2:int, 3:int} Anfrage, geteiltes lokales Foto, nur-Anfrage-Foto, geteiltes OneDrive-Foto */
    private function anfrageMitFotos(): array
    {
        $anfrage = (new AnfrageRepository($this->pdo))->anlegen('sammlung', null, [
            'name' => 'Erika Beispiel', 'email' => 'erika@test.emc78.de', 'telefon' => null, 'nachricht' => 'Kiste', 'wunsch' => 'spenden',
        ], new \DateTimeImmutable('2026-10-01 12:00:00'));
        $this->pdo->exec("INSERT INTO magazin_artikel (bezeichnung, kategorie_id) SELECT 'Kiste', id FROM magazin_kategorie WHERE schluessel = 'sonstiges'");
        $artikel = (int) $this->pdo->lastInsertId();
        $geteilt = $this->dokument('FOTO1', 'jpg');
        $nurAnfrage = $this->dokument('FOTO2', 'jpg');
        $schonDort = $this->dokument('FOTO3', 'jpg');
        $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'ITEM-3', onedrive_drive_id = 'D1' WHERE id = ?")->execute([$schonDort]);
        foreach ([$geteilt, $nurAnfrage, $schonDort] as $d) {
            $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($d, 'boerse_anfrage', $anfrage)");
        }
        foreach ([$geteilt, $schonDort] as $d) {
            $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($d, 'magazin_artikel', $artikel)");
        }
        return [$anfrage, $geteilt, $nurAnfrage, $schonDort];
    }

    /** @return list<int> */
    private function hochladeAuftraege(): array
    {
        return array_map('intval', $this->pdo->query("SELECT dokument_id FROM onedrive_auftrag WHERE art = 'hochladen' ORDER BY dokument_id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_ueberlebende_fotos_werden_vorgemerkt(): void
    {
        $this->verbinde();
        [$anfrage, $geteilt, $nurAnfrage] = $this->anfrageMitFotos();

        $this->dienst()->loesche($anfrage, null);

        $this->assertSame([$geteilt], $this->hochladeAuftraege(), 'nur das lokale Foto, das am Artikel bleibt');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM dokument WHERE id = $nurAnfrage")->fetchColumn());
        $this->assertSame([], $this->http->anfragen, 'nur vorgemerkt, nicht während der Anfrage hochgeladen');
    }

    public function test_ohne_onedrive_nichts_vorgemerkt(): void
    {
        [$anfrage] = $this->anfrageMitFotos();
        $this->dienst()->raeumeAuf(new \DateTimeImmutable('2027-10-02 12:00:00'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_anfrage')->fetchColumn());
        $this->assertSame([], $this->hochladeAuftraege());
    }
}
