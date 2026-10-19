<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\Betrieb\Einstellungen;
use Emc\Core\Tresor;
use Emc\OneDrive\TokenSpeicher;
use Emc\Tests\Integration\DbTestCase;

final class TokenSpeicherTest extends DbTestCase
{
    private function speicher(): TokenSpeicher
    {
        return new TokenSpeicher(new Einstellungen($this->pdo), new Tresor(base64_encode(str_repeat('k', 32))));
    }

    public function testSpeichertVerschluesselt(): void
    {
        $s = $this->speicher();
        $token = ['refresh_token' => 'GEHEIMER-REFRESH', 'access_token' => 'GEHEIMER-ACCESS', 'ablauf' => 1900000000];
        $s->speichere($token);
        $roh = (string) (new Einstellungen($this->pdo))->hole('onedrive_token');
        self::assertNotSame('', $roh);
        self::assertStringNotContainsString('GEHEIMER', $roh);
        $alles = json_encode($this->pdo->query('SELECT * FROM einstellung')->fetchAll(\PDO::FETCH_ASSOC));
        self::assertStringNotContainsString('GEHEIMER', (string) $alles);
        self::assertSame($token, $s->lade());
        self::assertSame('verbunden', $s->status());
    }

    public function testLeerUndLoeschen(): void
    {
        $s = $this->speicher();
        self::assertNull($s->lade());
        self::assertSame('getrennt', $s->status());
        $s->speichere(['refresh_token' => 'a', 'access_token' => 'b', 'ablauf' => 1]);
        $s->setzeStatus('abgelaufen');
        self::assertSame('abgelaufen', $s->status());
        $s->loesche();
        self::assertNull($s->lade());
        self::assertSame('getrennt', $s->status());
    }

    public function testManipuliertesTokenErgibtNull(): void
    {
        $s = $this->speicher();
        (new Einstellungen($this->pdo))->setze('onedrive_token', 'Müll');
        self::assertNull($s->lade());
    }
}
