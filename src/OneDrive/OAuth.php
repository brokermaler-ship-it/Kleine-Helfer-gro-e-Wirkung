<?php

declare(strict_types=1);

namespace Emc\OneDrive;

final class OAuth
{
    private const BASIS = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/';
    private const SCOPES = 'offline_access Files.ReadWrite.All User.Read';

    public function __construct(
        private HttpClient $http,
        private string $clientId,
        #[\SensitiveParameter] private string $clientSecret,
        private string $rueckrufUrl,
        private int $zeitlimit = 20,
    ) {
    }

    public function anmeldeUrl(string $state): string
    {
        return self::BASIS . 'authorize?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->rueckrufUrl,
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{refresh_token:string,access_token:string,ablauf:int} */
    public function tauscheCode(#[\SensitiveParameter] string $code): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->rueckrufUrl,
        ], null);
    }

    /** @return array{refresh_token:string,access_token:string,ablauf:int} */
    public function erneuere(#[\SensitiveParameter] string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $refreshToken);
    }

    /**
     * @param array<string,string> $felder
     * @return array{refresh_token:string,access_token:string,ablauf:int}
     */
    private function token(array $felder, ?string $alterRefresh): array
    {
        $felder += [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => self::SCOPES,
        ];
        $a = $this->http->anfrage(
            'POST',
            self::BASIS . 'token',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query($felder, '', '&', PHP_QUERY_RFC3986),
            $this->zeitlimit
        );
        $d = $a->json();
        if ($a->status !== 200 || !isset($d['access_token'])) {
            if (($d['error'] ?? '') === 'invalid_grant') {
                throw new GraphFehler('Die Verbindung zu OneDrive ist abgelaufen – bitte neu verbinden.', $a->status, true);
            }
            throw GraphFehler::ausAntwort($a, 'Anmeldung bei Microsoft fehlgeschlagen' . (isset($d['error']) && is_string($d['error']) ? ': ' . $d['error'] : ''));
        }
        $refresh = (string) ($d['refresh_token'] ?? $alterRefresh ?? '');
        if ($refresh === '') {
            throw new GraphFehler('Microsoft hat kein Refresh-Token geliefert.', $a->status);
        }
        return [
            'refresh_token' => $refresh,
            'access_token' => (string) $d['access_token'],
            'ablauf' => time() + max(60, (int) ($d['expires_in'] ?? 3600)),
        ];
    }
}
