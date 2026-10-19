<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\OneDrive;

use Emc\OneDrive\GraphFehler;
use Emc\OneDrive\OAuth;
use Emc\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OAuthTest extends TestCase
{
    private function oauth(FakeHttpClient $http): OAuth
    {
        return new OAuth($http, 'cid', 'sec ret', 'https://x.de/admin/onedrive/rueckruf');
    }

    public function testAnmeldeUrl(): void
    {
        $url = $this->oauth(new FakeHttpClient())->anmeldeUrl('st123');
        self::assertStringStartsWith('https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame('cid', $q['client_id']);
        self::assertSame('code', $q['response_type']);
        self::assertSame('https://x.de/admin/onedrive/rueckruf', $q['redirect_uri']);
        self::assertSame('offline_access Files.ReadWrite.All User.Read', $q['scope']);
        self::assertSame('st123', $q['state']);
        self::assertSame('select_account', $q['prompt']);
        self::assertArrayNotHasKey('client_secret', $q);
    }

    public function testCodeTausch(): void
    {
        $http = (new FakeHttpClient())->antwort(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600]);
        $vorher = time();
        $t = $this->oauth($http)->tauscheCode('CODE');
        self::assertSame('AT', $t['access_token']);
        self::assertSame('RT', $t['refresh_token']);
        self::assertGreaterThanOrEqual($vorher + 3600, $t['ablauf']);
        $a = $http->anfragen[0];
        self::assertSame('POST', $a['methode']);
        self::assertSame('https://login.microsoftonline.com/consumers/oauth2/v2.0/token', $a['url']);
        parse_str((string) $a['koerper'], $f);
        self::assertSame('authorization_code', $f['grant_type']);
        self::assertSame('CODE', $f['code']);
        self::assertSame('sec ret', $f['client_secret']);
        self::assertSame('https://x.de/admin/onedrive/rueckruf', $f['redirect_uri']);
    }

    public function testErneuernBehaeltAltenRefreshTokenWennKeinNeuer(): void
    {
        $http = (new FakeHttpClient())->antwort(200, ['access_token' => 'AT2', 'expires_in' => 3600]);
        $t = $this->oauth($http)->erneuere('ALT');
        self::assertSame('ALT', $t['refresh_token']);
        parse_str((string) $http->anfragen[0]['koerper'], $f);
        self::assertSame('refresh_token', $f['grant_type']);
        self::assertSame('ALT', $f['refresh_token']);
    }

    public function testInvalidGrantIstAbgelaufen(): void
    {
        $http = (new FakeHttpClient())->antwort(400, ['error' => 'invalid_grant', 'error_description' => 'expired']);
        try {
            $this->oauth($http)->erneuere('RT');
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler $e) {
            self::assertTrue($e->istAbgelaufen());
            self::assertFalse($e->wiederholbar());
        }
    }

    public function testServerfehlerIstWiederholbar(): void
    {
        $http = (new FakeHttpClient())->antwort(503, 'down');
        try {
            $this->oauth($http)->erneuere('RT');
            self::fail('Ausnahme erwartet');
        } catch (GraphFehler $e) {
            self::assertFalse($e->istAbgelaufen());
            self::assertTrue($e->wiederholbar());
        }
    }
}
