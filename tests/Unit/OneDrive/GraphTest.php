<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\OneDrive;

use Emc\Betrieb\Einstellungen;
use Emc\Core\Tresor;
use Emc\OneDrive\Graph;
use Emc\OneDrive\GraphFehler;
use Emc\OneDrive\OAuth;
use Emc\OneDrive\TokenSpeicher;
use Emc\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class GraphTest extends TestCase
{
    private FakeHttpClient $http;
    private SpeicherAttrappe $speicher;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->speicher = new SpeicherAttrappe();
        $this->speicher->speichere(['refresh_token' => 'RT', 'access_token' => 'AT', 'ablauf' => time() + 3600]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $f) {
            @unlink($f);
        }
    }

    private function graph(): Graph
    {
        return new Graph($this->http, new OAuth($this->http, 'cid', 'sec', 'https://x.de/r'), $this->speicher);
    }

    private function datei(int $bytes): string
    {
        $pfad = tempnam(sys_get_temp_dir(), 'od');
        $this->temp[] = $pfad;
        file_put_contents($pfad, str_repeat('x', $bytes));
        return $pfad;
    }

    public function testIch(): void
    {
        $this->http->antwort(200, ['displayName' => 'Verein', 'userPrincipalName' => 'v@example.de']);
        self::assertSame('Verein', $this->graph()->ich()['displayName']);
        self::assertSame('https://graph.microsoft.com/v1.0/me', $this->http->anfragen[0]['url']);
        self::assertSame('Bearer AT', $this->http->anfragen[0]['kopf']['Authorization']);
    }

    public function testTokenWirdBei401EinmalErneuert(): void
    {
        $this->http->antwort(401, ['error' => ['code' => 'InvalidAuthenticationToken']])
            ->antwort(200, ['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3600])
            ->antwort(200, ['displayName' => 'Verein']);
        self::assertSame('Verein', $this->graph()->ich()['displayName']);
        self::assertCount(3, $this->http->anfragen);
        self::assertSame('Bearer AT2', $this->http->anfragen[2]['kopf']['Authorization']);
        self::assertSame('AT2', $this->speicher->lade()['access_token']);
        self::assertSame('RT2', $this->speicher->lade()['refresh_token']);
    }

    public function testZweites401WirdNichtWiederholt(): void
    {
        $this->http->antwort(401, '')
            ->antwort(200, ['access_token' => 'AT2', 'expires_in' => 3600])
            ->antwort(401, '');
        $this->expectException(GraphFehler::class);
        $this->graph()->ich();
    }

    public function testTokenWirdVorAblaufErneuert(): void
    {
        $this->speicher->speichere(['refresh_token' => 'RT', 'access_token' => 'ALT', 'ablauf' => time() + 120]);
        $this->http->antwort(200, ['access_token' => 'NEU', 'expires_in' => 3600])
            ->antwort(200, ['displayName' => 'V']);
        $this->graph()->ich();
        self::assertStringContainsString('/token', $this->http->anfragen[0]['url']);
        self::assertSame('Bearer NEU', $this->http->anfragen[1]['kopf']['Authorization']);
    }

    public function testInvalidGrantSetztStatusAbgelaufen(): void
    {
        $this->speicher->speichere(['refresh_token' => 'RT', 'access_token' => 'ALT', 'ablauf' => time() - 10]);
        $this->http->antwort(400, ['error' => 'invalid_grant']);
        try {
            $this->graph()->ich();
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler $e) {
            self::assertTrue($e->istAbgelaufen());
        }
        self::assertSame('abgelaufen', $this->speicher->status());
    }

    public function testNichtVerbunden(): void
    {
        $this->speicher->loesche();
        $this->expectException(GraphFehler::class);
        $this->graph()->ich();
    }

    public function testKleineDateiPutMitKodiertemPfad(): void
    {
        $this->http->antwort(201, ['id' => 'ITEM1', 'name' => 'Foto 1.jpg']);
        $r = $this->graph()->hochladen('d!1', 'P1', 'Fahrzeuge/V 100 Ä Lok/Foto #1.jpg', $this->datei(100));
        $a = $this->http->anfragen[0];
        self::assertSame('PUT', $a['methode']);
        self::assertSame(
            'https://graph.microsoft.com/v1.0/drives/d%211/items/P1:/Fahrzeuge/V%20100%20%C3%84%20Lok/Foto%20%231.jpg:/content?@microsoft.graph.conflictBehavior=rename',
            $a['url']
        );
        self::assertSame(str_repeat('x', 100), $a['koerper']);
        self::assertSame('ITEM1', $r['item_id']);
        self::assertSame('Fahrzeuge/V 100 Ä Lok/Foto 1.jpg', $r['pfad'], 'tatsächlicher Name nach Umbenennung');
    }

    public function testGrosseDateiUploadSession(): void
    {
        $gesamt = 7 * 1048576;
        $this->http->antwort(200, ['uploadUrl' => 'https://upload.example/sess'])
            ->antwort(202, ['nextExpectedRanges' => ['3276800-']])
            ->antwort(202, ['nextExpectedRanges' => ['6553600-']])
            ->antwort(201, ['id' => 'BIG', 'name' => 'gross.bin']);
        $r = $this->graph()->hochladen('D', 'P', 'Sonstiges/gross.bin', $this->datei($gesamt));
        self::assertSame('BIG', $r['item_id']);
        $a = $this->http->anfragen;
        self::assertCount(4, $a);
        self::assertSame('POST', $a[0]['methode']);
        self::assertStringEndsWith('/drives/D/items/P:/Sonstiges/gross.bin:/createUploadSession', $a[0]['url']);
        self::assertSame(['item' => ['@microsoft.graph.conflictBehavior' => 'rename']], json_decode((string) $a[0]['koerper'], true));
        $bereiche = ['bytes 0-3276799/7340032', 'bytes 3276800-6553599/7340032', 'bytes 6553600-7340031/7340032'];
        foreach ($bereiche as $i => $bereich) {
            $c = $a[$i + 1];
            self::assertSame('PUT', $c['methode']);
            self::assertSame('https://upload.example/sess', $c['url']);
            self::assertSame($bereich, $c['kopf']['Content-Range']);
            self::assertSame((string) strlen((string) $c['koerper']), $c['kopf']['Content-Length']);
            self::assertArrayNotHasKey('Authorization', $c['kopf']);
        }
    }

    public function testRate429MitRetryAfter(): void
    {
        $this->http->antwort(429, '', ['Retry-After' => '37']);
        try {
            $this->graph()->ich();
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler $e) {
            self::assertTrue($e->wiederholbar());
            self::assertSame(37, $e->warteSekunden());
        }
    }

    public function testKleinerPutHatLangesZeitlimit(): void
    {
        $this->http->antwort(201, ['id' => 'I', 'name' => 'a.txt']);
        $this->graph()->hochladen('D', 'P', 'a.txt', $this->datei(10));
        self::assertGreaterThanOrEqual(120, $this->http->anfragen[0]['zeitlimit']);
    }

    public function testRetryAfterAlsDatum(): void
    {
        $this->http->antwort(503, '', ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 90) . ' GMT']);
        try {
            $this->graph()->ich();
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler $e) {
            self::assertEqualsWithDelta(90, $e->warteSekunden(), 3);
        }
    }

    public function testRedirectNurHttpsUndNur2xx(): void
    {
        $this->http->antwort(302, '', ['Location' => 'http://evil.example/x']);
        try {
            $this->graph()->inhalt('D', 'I');
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler) {
            self::assertCount(1, $this->http->anfragen);
        }
        $this->http->antwort(302, '', ['Location' => 'https://cdn.example/x'])->antwort(304, '');
        $this->expectException(GraphFehler::class);
        $this->graph()->inhalt('D', 'I');
    }

    public function testFremdeNextLinkWirdNichtGefolgt(): void
    {
        $this->http->antwort(200, ['value' => [], '@odata.nextLink' => 'https://evil.example/n'])
            ->antwort(200, ['value' => []]);
        $this->graph()->ordnerListe(null, null);
        foreach ($this->http->anfragen as $a) {
            self::assertStringStartsWith('https://graph.microsoft.com/v1.0/', $a['url']);
        }
    }

    public function testHinzugefuegterGeteilterOrdnerInWurzel(): void
    {
        $this->http->antwort(200, ['value' => [
            ['id' => 'L1', 'name' => 'Verknuepfung', 'remoteItem' => ['id' => 'R9', 'name' => 'EMC', 'folder' => [], 'parentReference' => ['driveId' => 'D9']]],
        ]])->antwort(200, ['value' => [
            ['id' => 'S', 'remoteItem' => ['id' => 'R9', 'name' => 'EMC', 'folder' => [], 'parentReference' => ['driveId' => 'D9']]],
        ]]);
        self::assertSame(
            [['drive_id' => 'D9', 'item_id' => 'R9', 'name' => 'EMC', 'geteilt' => true]],
            $this->graph()->ordnerListe(null, null)
        );
    }

    public function testSharedWithMeFehlerWirdIgnoriert(): void
    {
        $this->http->antwort(200, ['value' => [
            ['id' => 'F1', 'name' => 'Eigen', 'folder' => [], 'parentReference' => ['driveId' => 'D1']],
        ]])->antwort(400, ['error' => ['code' => 'invalidRequest']]);
        $liste = $this->graph()->ordnerListe(null, null);
        self::assertCount(1, $liste);
        self::assertSame('F1', $liste[0]['item_id']);
    }

    public function testLoeschen404IstErfolg(): void
    {
        $this->http->antwort(404, ['error' => []])->antwort(204, '');
        $g = $this->graph();
        $g->loeschen('D', 'I');
        $g->loeschen('D', 'I2');
        self::assertSame('DELETE', $this->http->anfragen[0]['methode']);
        self::assertSame('https://graph.microsoft.com/v1.0/drives/D/items/I', $this->http->anfragen[0]['url']);
    }

    public function testLoeschenFehler500(): void
    {
        $this->http->antwort(500, '');
        $this->expectException(GraphFehler::class);
        $this->graph()->loeschen('D', 'I');
    }

    public function testOrdnerListeWurzelUndGeteilt(): void
    {
        $this->http
            ->antwort(200, ['value' => [
                ['id' => 'F1', 'name' => 'Eigen', 'folder' => ['childCount' => 1], 'parentReference' => ['driveId' => 'D1']],
                ['id' => 'X', 'name' => 'datei.txt', 'file' => [], 'parentReference' => ['driveId' => 'D1']],
            ]])
            ->antwort(200, ['value' => [
                ['id' => 'S1', 'remoteItem' => ['id' => 'R1', 'name' => 'EMC', 'folder' => [], 'parentReference' => ['driveId' => 'D2']]],
                ['id' => 'S2', 'remoteItem' => ['id' => 'R2', 'name' => 'Bild.png', 'file' => [], 'parentReference' => ['driveId' => 'D2']]],
            ]]);
        $liste = $this->graph()->ordnerListe(null, null);
        self::assertSame([
            ['drive_id' => 'D1', 'item_id' => 'F1', 'name' => 'Eigen', 'geteilt' => false],
            ['drive_id' => 'D2', 'item_id' => 'R1', 'name' => 'EMC', 'geteilt' => true],
        ], $liste);
        self::assertStringContainsString('/me/drive/root/children', $this->http->anfragen[0]['url']);
        self::assertStringContainsString('/me/drive/sharedWithMe', $this->http->anfragen[1]['url']);
    }

    public function testOrdnerListeUnterordner(): void
    {
        $this->http->antwort(200, ['value' => [
            ['id' => 'U1', 'name' => 'Unter', 'folder' => [], 'parentReference' => ['driveId' => 'D2']],
            ['id' => 'U2', 'name' => 'a.txt', 'file' => [], 'parentReference' => ['driveId' => 'D2']],
        ]]);
        $liste = $this->graph()->ordnerListe('D2', 'R1');
        self::assertCount(1, $liste);
        self::assertSame('U1', $liste[0]['item_id']);
        self::assertStringEndsWith('/drives/D2/items/R1/children', explode('?', $this->http->anfragen[0]['url'])[0]);
    }

    public function testInhaltFolgtRedirectOhneAuthorization(): void
    {
        $this->http->antwort(302, '', ['Location' => 'https://cdn.example/dl?x=1'])
            ->antwort(200, 'BINAER');
        $a = $this->graph()->inhalt('D', 'I');
        self::assertSame('BINAER', $a->koerper);
        self::assertSame('https://cdn.example/dl?x=1', $this->http->anfragen[1]['url']);
        self::assertArrayNotHasKey('Authorization', $this->http->anfragen[1]['kopf']);
    }

    public function testInhaltNachDateiSchreibtInDateiOhneAuthorizationAmZiel(): void
    {
        $ziel = tempnam(sys_get_temp_dir(), 'od');
        $this->temp[] = $ziel;
        $this->http->antwort(302, '', ['Location' => 'https://cdn.example/dl?x=1'])->antwort(200, 'BINAER');
        $a = $this->graph()->inhaltNachDatei('D', 'I', $ziel);
        self::assertSame(200, $a->status);
        self::assertSame('BINAER', file_get_contents($ziel));
        self::assertSame('Bearer AT', $this->http->anfragen[0]['kopf']['Authorization']);
        self::assertSame('https://cdn.example/dl?x=1', $this->http->anfragen[1]['url']);
        self::assertArrayNotHasKey('Authorization', $this->http->anfragen[1]['kopf']);
    }

    public function testInhaltNachDateiLehntNichtHttpsUndFehlerstatusAb(): void
    {
        $ziel = tempnam(sys_get_temp_dir(), 'od');
        $this->temp[] = $ziel;
        $this->http->antwort(302, '', ['Location' => 'http://evil.example/x']);
        try {
            $this->graph()->inhaltNachDatei('D', 'I', $ziel);
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler) {
            self::assertTrue(true);
        }
        $this->http->antwort(503, '');
        $this->expectException(GraphFehler::class);
        $this->graph()->inhaltNachDatei('D', 'I', $ziel);
    }

    public function testInhalt404(): void
    {
        $this->http->antwort(404, '');
        $this->expectException(GraphFehler::class);
        $this->graph()->inhalt('D', 'I');
    }
}

/** Speicher im Arbeitsspeicher, damit Graph ohne Datenbank getestet werden kann. */
final class SpeicherAttrappe extends TokenSpeicher
{
    private ?array $token = null;
    private string $status = 'getrennt';

    public function __construct()
    {
    }

    public function speichere(array $token): void
    {
        $this->token = $token;
        $this->status = 'verbunden';
    }

    public function lade(): ?array
    {
        return $this->token;
    }

    public function loesche(): void
    {
        $this->token = null;
        $this->status = 'getrennt';
    }

    public function status(): string
    {
        return $this->status;
    }

    public function setzeStatus(string $status): void
    {
        $this->status = $status;
    }
}
