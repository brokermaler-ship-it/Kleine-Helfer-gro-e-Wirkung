<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\OneDrive\Auftraege;
use Emc\OneDrive\Uebertragung;

final class UebertragungTest extends OneDriveTestCase
{
    private \DateTimeImmutable $jetzt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jetzt = new \DateTimeImmutable('2026-10-19 12:00:00');
    }

    private function auftrag(int $dokId): int
    {
        return (new Auftraege($this->pdo))->neu('hochladen', $dokId, null, $this->jetzt);
    }

    public function testKonfiguriertErfordertAlleDreiWerte(): void
    {
        self::assertFalse(Uebertragung::konfiguriert([]));
        self::assertFalse(Uebertragung::konfiguriert(['onedrive_client_id' => 'a', 'onedrive_client_secret' => 'b']));
        self::assertFalse(Uebertragung::konfiguriert(['onedrive_client_id' => 'a', 'onedrive_client_secret' => '', 'app_schluessel' => 'c']));
        self::assertTrue(Uebertragung::konfiguriert(['onedrive_client_id' => 'a', 'onedrive_client_secret' => 'b', 'app_schluessel' => 'c']));
    }

    public function testAktivErfordertVerbundenUndZiel(): void
    {
        $u = $this->uebertragung();
        self::assertFalse($u->aktiv());
        $this->verbinde(false);
        self::assertFalse($u->aktiv());
        $this->verbinde(true);
        self::assertTrue($u->aktiv());
        $this->tokens->setzeStatus('abgelaufen');
        self::assertFalse($u->aktiv());
    }

    public function testInaktivVerarbeitetNichts(): void
    {
        $id = $this->dokument();
        $this->auftrag($id);
        self::assertSame(['erledigt' => 0, 'fehler' => 0], $this->uebertragung()->verarbeite(5, $this->jetzt));
        self::assertSame([], $this->http->anfragen);
    }

    public function testHochladenEntferntOriginalBehaeltVarianten(): void
    {
        $fz = $this->fahrzeug();
        $id = $this->dokument('PDFDATEN', 'pdf', $fz);
        $basis = $this->speicher . '/' . $this->dok($id)['pfad'];
        file_put_contents($basis . '.vorschau.jpg', 'v');
        file_put_contents($basis . '.anzeige.jpg', 'a');
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'Anleitung.pdf']);

        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);

        self::assertSame(['erledigt' => 1, 'fehler' => 0], $r);
        $d = $this->dok($id);
        self::assertSame('onedrive', $d['ablage']);
        self::assertSame('ITEM1', $d['onedrive_item_id']);
        self::assertSame('Fahrzeuge/EMC-0001 V 200 060/Anleitung.pdf', $d['onedrive_pfad']);
        self::assertSame(0, (int) $d['original_lokal']);
        self::assertFileDoesNotExist($basis);
        self::assertFileExists($basis . '.vorschau.jpg');
        self::assertFileExists($basis . '.anzeige.jpg');
        self::assertSame('PUT', $this->http->anfragen[0]['methode']);
        self::assertStringContainsString('/drives/D1/items/ROOT:/Fahrzeuge/EMC-0001%20V%20200%20060/Anleitung.pdf:/content', $this->http->anfragen[0]['url']);
        self::assertSame('PDFDATEN', $this->http->anfragen[0]['koerper']);
        self::assertSame(1, (new Auftraege($this->pdo))->zaehle()['erledigt']);
    }

    public function testOhneFahrzeugNachSonstiges(): void
    {
        $id = $this->dokument();
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'I', 'name' => 'Anleitung (1).pdf']);
        $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame('Sonstiges/Anleitung (1).pdf', $this->dok($id)['onedrive_pfad']);
    }

    public function testOriginalBehaltenKonfiguriert(): void
    {
        $id = $this->dokument();
        $basis = $this->speicher . '/' . $this->dok($id)['pfad'];
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'I', 'name' => 'Anleitung.pdf']);
        $this->uebertragung(true)->verarbeite(5, $this->jetzt);
        $d = $this->dok($id);
        self::assertSame('onedrive', $d['ablage']);
        self::assertSame(1, (int) $d['original_lokal']);
        self::assertFileExists($basis);
    }

    public function testServerfehlerLaesstAuftragWartenUndDateiLokal(): void
    {
        $id = $this->dokument();
        $basis = $this->speicher . '/' . $this->dok($id)['pfad'];
        $this->verbinde();
        $aid = $this->auftrag($id);
        $this->http->antwort(503, '', ['Retry-After' => '120']);

        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);

        self::assertSame(['erledigt' => 0, 'fehler' => 1], $r);
        $d = $this->dok($id);
        self::assertSame('lokal', $d['ablage']);
        self::assertSame(1, (int) $d['original_lokal']);
        self::assertFileExists($basis);
        $z = $this->pdo->query("SELECT * FROM onedrive_auftrag WHERE id = $aid")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('wartet', $z['status']);
        self::assertSame(1, (int) $z['versuche']);
        self::assertSame('2026-10-19 12:02:00', $z['naechster_versuch']);
        self::assertNull($z['gesperrt_bis']);
    }

    public function testNochNichtFaelligWirdUebersprungen(): void
    {
        $id = $this->dokument();
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(503);
        $u = $this->uebertragung();
        $u->verarbeite(5, $this->jetzt);
        self::assertSame(['erledigt' => 0, 'fehler' => 0], $u->verarbeite(5, $this->jetzt->modify('+30 seconds')));
        self::assertCount(1, $this->http->anfragen);
    }

    public function testTokenAbgelaufenSetztStatusAuftragBleibt(): void
    {
        $id = $this->dokument();
        $this->tokens->speichere(['refresh_token' => 'RT', 'access_token' => 'AT', 'ablauf' => time() - 10]);
        $this->einstellungen->setze('onedrive_ziel', json_encode(['drive_id' => 'D1', 'item_id' => 'ROOT', 'name' => 'EMC']));
        $aid = $this->auftrag($id);
        $this->auftrag($this->dokument());
        $this->http->antwort(400, ['error' => 'invalid_grant']);

        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);

        self::assertSame('abgelaufen', $this->tokens->status());
        self::assertSame(0, $r['erledigt']);
        self::assertSame('lokal', $this->dok($id)['ablage']);
        $z = $this->pdo->query("SELECT status, versuche, gesperrt_bis FROM onedrive_auftrag WHERE id = $aid")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['status' => 'wartet', 'versuche' => 0, 'gesperrt_bis' => null], ['status' => $z['status'], 'versuche' => (int) $z['versuche'], 'gesperrt_bis' => $z['gesperrt_bis']]);
        self::assertSame(2, (new Auftraege($this->pdo))->zaehle()['wartet']);
        self::assertCount(1, $this->http->anfragen, 'nach Ablauf wird der Rest nicht mehr versucht');
    }

    public function testFehlendeLokaleDateiWirdFehlerversuch(): void
    {
        $id = $this->dokument();
        unlink($this->speicher . '/' . $this->dok($id)['pfad']);
        $this->verbinde();
        $this->auftrag($id);
        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame(1, $r['fehler']);
        self::assertSame([], $this->http->anfragen);
    }

    public function testAuftragFuerGeloeschtesDokumentIstErledigt(): void
    {
        $this->verbinde();
        $this->auftrag(999);
        self::assertSame(['erledigt' => 1, 'fehler' => 0], $this->uebertragung()->verarbeite(5, $this->jetzt));
    }

    public function testLoeschauftrag(): void
    {
        $this->verbinde();
        (new Auftraege($this->pdo))->neu('loeschen', null, 'ITEM9', $this->jetzt);
        $this->http->antwort(204);
        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame(1, $r['erledigt']);
        self::assertSame('DELETE', $this->http->anfragen[0]['methode']);
        self::assertStringEndsWith('/drives/D1/items/ITEM9', $this->http->anfragen[0]['url']);
    }

    public function testDokumentLoeschenLegtLoeschauftragAn(): void
    {
        $id = $this->dokument();
        $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'ITEM7', original_lokal = 0 WHERE id = ?")->execute([$id]);
        $repo = new \Emc\Dateien\DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo));
        $repo->loeschen($id);
        $z = $this->pdo->query('SELECT art, item_id, status FROM onedrive_auftrag')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['art' => 'loeschen', 'item_id' => 'ITEM7', 'status' => 'wartet']], $z);
        $this->verbinde();
        $this->http->antwort(204);
        self::assertSame(1, $this->uebertragung()->verarbeite(5)['erledigt']);
    }

    public function testLokalesDokumentLoeschenErzeugtKeinenAuftrag(): void
    {
        $id = $this->dokument();
        (new \Emc\Dateien\DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo)))->loeschen($id);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM onedrive_auftrag')->fetchColumn());
    }

    public function testUmzugLegtAuftraegeFuerLokaleDokumenteAn(): void
    {
        $a = $this->dokument();
        $b = $this->dokument();
        $c = $this->dokument();
        $this->pdo->exec("UPDATE dokument SET ablage = 'onedrive' WHERE id = $c");
        $this->verbinde();
        $u = $this->uebertragung();
        self::assertSame(2, $u->umzug($this->jetzt));
        self::assertSame(0, $u->umzug($this->jetzt), 'kein zweiter Auftrag für dieselben Dokumente');
        $ids = array_map('intval', $this->pdo->query("SELECT dokument_id FROM onedrive_auftrag WHERE art = 'hochladen' ORDER BY dokument_id")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$a, $b], $ids);
    }

    public function testUmzugOhneVerbindungTutNichts(): void
    {
        $this->dokument();
        self::assertSame(0, $this->uebertragung()->umzug($this->jetzt));
    }

    public function testWiederholterLaufDoppeltNichts(): void
    {
        $id = $this->dokument();
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'I', 'name' => 'Anleitung.pdf']);
        $u = $this->uebertragung();
        $u->verarbeite(5, $this->jetzt);
        $u->verarbeite(5, $this->jetzt->modify('+1 hour'));
        self::assertCount(1, $this->http->anfragen);
    }
}
