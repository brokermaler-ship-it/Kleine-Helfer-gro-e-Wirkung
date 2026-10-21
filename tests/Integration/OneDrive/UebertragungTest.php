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
        $ok = ['oeffentliche_url' => 'https://verein.example', 'onedrive_client_id' => 'a', 'onedrive_client_secret' => 'b', 'app_schluessel' => 'c'];
        self::assertTrue(Uebertragung::konfiguriert($ok));
        self::assertFalse(Uebertragung::konfiguriert(['oeffentliche_url' => 'http://verein.example'] + $ok));
        self::assertFalse(Uebertragung::konfiguriert(['oeffentliche_url' => ''] + $ok));
        self::assertSame('https://verein.example/emc/admin/onedrive/rueckruf', Uebertragung::rueckrufUrl(['basis_url' => '/emc/', 'oeffentliche_url' => 'https://verein.example/'] + $ok));
        self::assertSame('https://verein.example/admin/onedrive/rueckruf', Uebertragung::rueckrufUrl($ok));
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

    public function test_magazinfoto_in_ordner_magazin(): void
    {
        $kategorie = (int) $this->pdo->query("SELECT id FROM magazin_kategorie WHERE schluessel = 'sonstiges'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO magazin_artikel (bezeichnung, kategorie_id) VALUES (?, ?)')->execute(['Märklin C-Gleis', $kategorie]);
        $artikel = (int) $this->pdo->lastInsertId();
        $id = $this->dokument();
        $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($id, 'magazin_artikel', $artikel)");
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'I', 'name' => 'Anleitung.pdf']);
        $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame('Magazin/M' . $artikel . ' Märklin C-Gleis/Anleitung.pdf', $this->dok($id)['onedrive_pfad']);
    }

    public function test_fotos_aus_boersenanfragen_bleiben_lokal(): void
    {
        $id = $this->dokument();
        $this->pdo->exec("INSERT INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES ($id, 'boerse_anfrage', 1)");
        $this->verbinde();
        self::assertSame(0, $this->uebertragung()->umzug($this->jetzt));
        $this->auftrag($id);
        $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame([], $this->http->anfragen);
        self::assertSame('lokal', $this->dok($id)['ablage']);
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

    public function testAbgelaufeneSperreImStapelFuehrtNichtZuDoppeltemUpload(): void
    {
        $a = $this->dokument();
        $b = $this->dokument();
        $this->verbinde();
        $aufA = $this->auftrag($a);
        $aufB = $this->auftrag($b);
        $andere = new Auftraege($this->pdo);
        $uebernommen = [];
        // Während Auftrag A hochgeladen wird, läuft die Sperre ab und ein anderer Lauf übernimmt Auftrag B.
        $this->http->dann(function () use ($andere, &$uebernommen) {
            $uebernommen = $andere->beanspruche(5, $this->jetzt->modify('+11 minutes'));
            return new \Emc\OneDrive\HttpAntwort(201, [], json_encode(['id' => 'IA', 'name' => 'Anleitung.pdf']));
        });
        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame(['erledigt' => 1, 'fehler' => 0], $r);
        self::assertSame([$aufA, $aufB], array_map(fn($z) => (int) $z['id'], $uebernommen));
        self::assertCount(1, $this->http->anfragen, 'Auftrag B wurde vom ersten Lauf nicht mehr angefasst');
        self::assertSame('lokal', $this->dok($b)['ablage']);
        self::assertSame('wartet', $this->pdo->query("SELECT status FROM onedrive_auftrag WHERE id = $aufB")->fetchColumn());
        // Abschluss mit altem Token wirkt nicht: Auftrag A gehört jetzt dem anderen Lauf.
        self::assertSame('wartet', $this->pdo->query("SELECT status FROM onedrive_auftrag WHERE id = $aufA")->fetchColumn());
    }

    public function testZweiterUploadDesselbenDokumentsWirdWiederEntfernt(): void
    {
        $id = $this->dokument();
        $this->verbinde();
        $this->auftrag($id);
        $this->http->dann(function () use ($id) {
            // Ein paralleler Lauf hat das Dokument inzwischen übertragen.
            $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'ERSTER' WHERE id = ?")->execute([$id]);
            return new \Emc\OneDrive\HttpAntwort(201, [], json_encode(['id' => 'ZWEITER', 'name' => 'Anleitung.pdf']));
        })->antwort(204);
        $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame('DELETE', $this->http->anfragen[1]['methode']);
        self::assertStringEndsWith('/items/ZWEITER', $this->http->anfragen[1]['url']);
        self::assertSame('ERSTER', $this->dok($id)['onedrive_item_id']);
    }

    public function testNurGenauDerAngegebeneAuftragWirdVerarbeitet(): void
    {
        $alt = $this->dokument();
        $neu = $this->dokument();
        $this->verbinde();
        $this->auftrag($alt);
        $neuId = $this->auftrag($neu);
        $this->http->antwort(201, ['id' => 'N', 'name' => 'Anleitung.pdf']);
        $r = $this->uebertragung()->verarbeite(1, $this->jetzt, $neuId);
        self::assertSame(1, $r['erledigt']);
        self::assertSame('onedrive', $this->dok($neu)['ablage']);
        self::assertSame('lokal', $this->dok($alt)['ablage']);
    }

    public function testArtFilterVerarbeitetNurLoeschauftraege(): void
    {
        $id = $this->dokument();
        $this->verbinde();
        $this->auftrag($id);
        (new Auftraege($this->pdo))->neu('loeschen', null, 'IT', $this->jetzt);
        $this->http->antwort(204);
        $r = $this->uebertragung()->verarbeite(5, $this->jetzt, null, 'loeschen');
        self::assertSame(1, $r['erledigt']);
        self::assertCount(1, $this->http->anfragen);
        self::assertSame('lokal', $this->dok($id)['ablage']);
    }

    public function testDauerhafteFehlerGehenSofortAufFehler(): void
    {
        foreach ([403, 404, 413, 507] as $status) {
            $this->setUp();
            $id = $this->dokument();
            $this->verbinde();
            $aid = $this->auftrag($id);
            $this->http->antwort($status);
            $r = $this->uebertragung()->verarbeite(5, $this->jetzt);
            self::assertSame(1, $r['fehler'], "HTTP $status");
            $z = $this->pdo->query("SELECT status, letzter_fehler FROM onedrive_auftrag WHERE id = $aid")->fetch(\PDO::FETCH_ASSOC);
            self::assertSame('fehler', $z['status'], "HTTP $status");
            self::assertStringContainsString((string) $status, (string) $z['letzter_fehler']);
        }
    }

    public function testVorUebertragungWirdOriginalVermerktUndSpaeterGeloescht(): void
    {
        $id = $this->dokument();
        $basis = $this->speicher . '/' . $this->dok($id)['pfad'];
        $this->verbinde();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'I', 'name' => 'Anleitung.pdf']);
        $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame(0, (int) $this->dok($id)['original_lokal']);
        self::assertFileDoesNotExist($basis);
    }

    public function testErledigteAuftraegeWerdenNachDreissigTagenGeloescht(): void
    {
        $a = new Auftraege($this->pdo);
        $alt = $a->neu('hochladen', 1, null, $this->jetzt);
        $a->erledigt($alt);
        $this->pdo->exec("UPDATE onedrive_auftrag SET geaendert_am = '2026-08-01 00:00:00' WHERE id = $alt");
        $this->verbinde();
        $this->uebertragung()->verarbeite(5, $this->jetzt);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM onedrive_auftrag')->fetchColumn());
    }

    public function testTokenEndpunktFehlerStoppiertLaufOhneAuftraegeZuVerlieren(): void
    {
        $this->tokens->speichere(['refresh_token' => 'RT', 'access_token' => 'AT', 'ablauf' => time() - 10]);
        $this->einstellungen->setze('onedrive_ziel', json_encode(['drive_id' => 'D1', 'item_id' => 'ROOT', 'name' => 'EMC']));
        $a = $this->auftrag($this->dokument());
        $b = $this->auftrag($this->dokument());
        (new Auftraege($this->pdo))->neu('loeschen', null, 'IT', $this->jetzt);
        $this->http->antwort(400, ['error' => 'invalid_client']);

        $r = $this->uebertragung()->verarbeite(5, $this->jetzt);

        self::assertSame(['erledigt' => 0, 'fehler' => 0], $r);
        self::assertCount(1, $this->http->anfragen, 'Lauf endet nach dem ersten Auftrag');
        self::assertSame('abgelaufen', $this->tokens->status());
        self::assertStringContainsString('invalid_client', (string) $this->einstellungen->hole('onedrive_letzter_fehler'));
        $z = $this->pdo->query('SELECT status, versuche FROM onedrive_auftrag ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['status' => 'wartet', 'versuche' => 0], ['status' => 'wartet', 'versuche' => 0], ['status' => 'wartet', 'versuche' => 0]], $z);
    }

    /** Uhr für das Zeitbudget: liefert nacheinander die angegebenen Sekundenwerte (der letzte bleibt stehen). */
    private static function uhr(float ...$werte): \Closure
    {
        return static function () use (&$werte): float {
            return count($werte) > 1 ? array_shift($werte) : $werte[0];
        };
    }

    public function testZeitbudgetBeginntNachAblaufKeineNeuenAuftraege(): void
    {
        $this->verbinde();
        $a = $this->dokument();
        $b = $this->dokument();
        $this->auftrag($a);
        $this->auftrag($b);
        $this->http->antwort(201, ['id' => 'I1', 'name' => 'Anleitung.pdf']);
        // Start bei 0 s, erster Auftrag bei 1 s, zweiter erst bei 26 s (Budget 25 s aufgebraucht).
        $u = $this->uebertragung(false, 25.0, self::uhr(0.0, 1.0, 26.0));

        self::assertSame(['erledigt' => 1, 'fehler' => 0], $u->verarbeite(5, $this->jetzt));
        self::assertSame('onedrive', $this->dok($a)['ablage']);
        self::assertSame('lokal', $this->dok($b)['ablage']);
        self::assertSame('wartet', $this->pdo->query("SELECT status FROM onedrive_auftrag WHERE dokument_id = $b")->fetchColumn());
        self::assertCount(1, $this->http->anfragen);
    }

    public function testZeitbudgetGiltAuchFuerVieleUploadsEinerAnfrage(): void
    {
        $this->verbinde();
        $u = $this->uebertragung(false, 25.0, self::uhr(0.0, 2.0, 2.0, 30.0));
        $this->http->antwort(201, ['id' => 'I1', 'name' => 'Anleitung.pdf']);
        $ids = [$this->dokument(), $this->dokument(), $this->dokument()];
        foreach ($ids as $id) {
            $u->einreihen($id);
        }
        self::assertSame('onedrive', $this->dok($ids[0])['ablage']);
        self::assertSame('lokal', $this->dok($ids[1])['ablage']);
        self::assertSame('lokal', $this->dok($ids[2])['ablage']);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE status = 'wartet'")->fetchColumn(), 'Rest bleibt in der Warteschlange');
        self::assertCount(1, $this->http->anfragen);
    }

    public function testOhneZeitbudgetUnbegrenzt(): void
    {
        $this->verbinde();
        $u = $this->uebertragung(false, 25.0, self::uhr(0.0, 100.0));
        self::assertFalse($u->zeitUebrig());
        $u->setzeZeitbudget(null);
        self::assertTrue($u->zeitUebrig());
    }

    public function testHochladenMerktLaufwerk(): void
    {
        $this->verbinde();
        $id = $this->dokument();
        $this->auftrag($id);
        $this->http->antwort(201, ['id' => 'I1', 'name' => 'Anleitung.pdf']);
        $this->uebertragung()->verarbeite(1, $this->jetzt);
        self::assertSame('D1', $this->dok($id)['onedrive_drive_id']);
        self::assertSame('D1', $this->uebertragung()->laufwerkFuer($this->dok($id)));
    }

    public function testLaufwerkFestschreibenUndDateienAusserhalb(): void
    {
        $this->verbinde();
        $a = $this->dokument();
        $b = $this->dokument();
        $this->pdo->exec("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'X' WHERE id = $a");
        $this->pdo->exec("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'Y', onedrive_drive_id = 'D0' WHERE id = $b");
        $this->pdo->exec("INSERT INTO onedrive_auftrag (art, item_id, naechster_versuch) VALUES ('loeschen', 'Z', NOW())");
        $u = $this->uebertragung();
        self::assertSame(1, $u->dateienAusserhalb('D1'), 'nur die Datei im Laufwerk D0');
        self::assertSame(2, $u->dateienAusserhalb('D2'));

        $u->laufwerkFestschreiben();
        self::assertSame('D1', $this->dok($a)['onedrive_drive_id']);
        self::assertSame('D0', $this->dok($b)['onedrive_drive_id']);
        self::assertSame('D1', $this->pdo->query("SELECT drive_id FROM onedrive_auftrag WHERE item_id = 'Z'")->fetchColumn());
        // Nach einem Zielwechsel folgt die alte Datei nicht dem neuen Laufwerk.
        $this->einstellungen->setze('onedrive_ziel', json_encode(['drive_id' => 'D2', 'item_id' => 'R', 'name' => 'Neu']));
        self::assertSame('D1', $u->laufwerkFuer($this->dok($a)));
    }

    public function testLoeschFehlerZuruecksetzen(): void
    {
        $this->verbinde();
        $this->pdo->exec("INSERT INTO onedrive_auftrag (art, item_id, status, versuche, naechster_versuch) VALUES ('loeschen', 'L1', 'fehler', 20, NOW()), ('hochladen', NULL, 'fehler', 20, NOW()), ('loeschen', 'L2', 'erledigt', 1, NOW())");
        self::assertSame(1, $this->uebertragung()->loeschFehlerZuruecksetzen($this->jetzt));
        $z = $this->pdo->query("SELECT item_id, status, versuche FROM onedrive_auftrag ORDER BY id")->fetchAll(\PDO::FETCH_NUM);
        self::assertSame([['L1', 'wartet', 0], [null, 'fehler', 20], ['L2', 'erledigt', 1]], array_map(fn($r) => [$r[0], $r[1], (int) $r[2]], $z));
    }

    public function testWartenLaengerAls(): void
    {
        $a = new Auftraege($this->pdo);
        $a->neu('hochladen', 1, null);
        self::assertSame(0, $a->wartenLaengerAls(60));
        $this->pdo->exec('UPDATE onedrive_auftrag SET erstellt_am = NOW() - INTERVAL 61 MINUTE');
        self::assertSame(1, $a->wartenLaengerAls(60));
        $this->pdo->exec("UPDATE onedrive_auftrag SET status = 'erledigt'");
        self::assertSame(0, $a->wartenLaengerAls(60));
    }
}
