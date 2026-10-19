<?php

declare(strict_types=1);

namespace Emc\OneDrive;

/** Schmaler Microsoft-Graph-Client (v1.0) für Ordnerauswahl, Hoch-/Herunterladen und Löschen. */
final class Graph
{
    public const BASIS = 'https://graph.microsoft.com/v1.0';
    public const KLEIN_MAX = 4194304;
    public const BLOCK = 3276800;
    private const RESTLAUFZEIT = 300;

    public function __construct(
        private HttpClient $http,
        private OAuth $oauth,
        private TokenSpeicher $speicher,
        private int $zeitlimit = 20,
    ) {
    }

    /** @return array<string,mixed> */
    public function ich(): array
    {
        return $this->json('GET', '/me');
    }

    /** @return list<array{drive_id:string,item_id:string,name:string,geteilt:bool}> */
    public function ordnerListe(?string $driveId, ?string $itemId): array
    {
        $liste = [];
        if ($driveId === null || $itemId === null) {
            foreach ($this->seiten('/me/drive/root/children?$top=200') as $e) {
                $this->ordnerAnhaengen($liste, $e, false);
            }
            // sharedWithMe ist von Microsoft als veraltet angekündigt: nur Ergänzung, Fehler werden ignoriert.
            try {
                foreach ($this->seiten('/me/drive/sharedWithMe') as $e) {
                    $this->ordnerAnhaengen($liste, is_array($e['remoteItem'] ?? null) ? $e['remoteItem'] : [], true);
                }
            } catch (GraphFehler) {
            }
            return $liste;
        }
        foreach ($this->seiten('/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId) . '/children?$top=200') as $e) {
            $this->ordnerAnhaengen($liste, $e, false);
        }
        return $liste;
    }

    /** @return array{item_id:string,pfad:string} */
    public function hochladen(string $driveId, string $elternId, string $relPfad, string $datei): array
    {
        $groesse = is_file($datei) && is_readable($datei) ? filesize($datei) : false;
        if ($groesse === false) {
            throw new GraphFehler('Die Datei für die Übertragung nach OneDrive ist nicht lesbar.');
        }
        $basis = '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($elternId) . ':/' . $this->pfadKodiert($relPfad) . ':';
        if ($groesse <= self::KLEIN_MAX) {
            $inhalt = file_get_contents($datei);
            if ($inhalt === false) {
                throw new GraphFehler('Die Datei für die Übertragung nach OneDrive ist nicht lesbar.');
            }
            $a = $this->sende('PUT', $basis . '/content?@microsoft.graph.conflictBehavior=rename', ['Content-Type' => 'application/octet-stream'], $inhalt, max($this->zeitlimit, 120));
            return $this->ergebnis($a->json(), $relPfad);
        }
        $s = $this->json('POST', $basis . '/createUploadSession', ['Content-Type' => 'application/json'], json_encode(
            ['item' => ['@microsoft.graph.conflictBehavior' => 'rename']],
            JSON_THROW_ON_ERROR
        ));
        $uploadUrl = (string) ($s['uploadUrl'] ?? '');
        if ($uploadUrl === '') {
            throw new GraphFehler('OneDrive hat keine Upload-Sitzung angelegt.');
        }
        $fh = fopen($datei, 'rb');
        if ($fh === false) {
            throw new GraphFehler('Die Datei für die Übertragung nach OneDrive ist nicht lesbar.');
        }
        try {
            for ($start = 0; $start < $groesse; $start += self::BLOCK) {
                fseek($fh, $start);
                $stueck = (string) fread($fh, self::BLOCK);
                $ende = $start + strlen($stueck) - 1;
                // Ohne Authorization: die Upload-URL ist vorab autorisiert.
                $a = $this->http->anfrage('PUT', $uploadUrl, [
                    'Content-Length' => (string) strlen($stueck),
                    'Content-Range' => 'bytes ' . $start . '-' . $ende . '/' . $groesse,
                ], $stueck, max($this->zeitlimit, 120));
                if ($a->status >= 400) {
                    throw GraphFehler::ausAntwort($a, 'Upload zu OneDrive fehlgeschlagen');
                }
                if ($ende + 1 >= $groesse) {
                    return $this->ergebnis($a->json(), $relPfad);
                }
            }
        } finally {
            fclose($fh);
        }
        throw new GraphFehler('Upload zu OneDrive wurde nicht abgeschlossen.');
    }

    public function loeschen(string $driveId, string $itemId): void
    {
        try {
            $this->sende('DELETE', '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId));
        } catch (GraphFehler $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
    }

    /** Liefert den Dateiinhalt; folgt der Weiterleitung auf die vorab autorisierte Adresse. */
    public function inhalt(string $driveId, string $itemId): HttpAntwort
    {
        $pfad = '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId) . '/content';
        $a = $this->anfrageMitToken('GET', self::BASIS . $pfad);
        if (in_array($a->status, [301, 302, 303, 307, 308], true)) {
            $ziel = $a->kopf['location'] ?? '';
            if (!str_starts_with($ziel, 'https://')) {
                throw new GraphFehler('Ungültige Weiterleitung von OneDrive.', $a->status);
            }
            $a = $this->http->anfrage('GET', $ziel, [], null, max($this->zeitlimit, 120));
        }
        if ($a->status < 200 || $a->status >= 300) {
            throw GraphFehler::ausAntwort($a, 'Datei konnte nicht aus OneDrive geladen werden');
        }
        return $a;
    }

    /**
     * Lädt den Dateiinhalt direkt in $zielDatei (kein Puffern im Arbeitsspeicher). Weiterleitung nur auf https,
     * ohne Authorization-Header an den Zielhost; nur 2xx gilt als Erfolg.
     */
    public function inhaltNachDatei(string $driveId, string $itemId, string $zielDatei): HttpAntwort
    {
        $url = self::BASIS . '/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($itemId) . '/content';
        $zeit = max($this->zeitlimit, 120);
        $a = $this->http->herunterladen($url, $zielDatei, ['Authorization' => 'Bearer ' . $this->zugriffstoken(false)], $zeit);
        if ($a->status === 401) {
            $a = $this->http->herunterladen($url, $zielDatei, ['Authorization' => 'Bearer ' . $this->zugriffstoken(true)], $zeit);
        }
        if (in_array($a->status, [301, 302, 303, 307, 308], true)) {
            $ziel = $a->kopf['location'] ?? '';
            if (!str_starts_with($ziel, 'https://')) {
                throw new GraphFehler('Ungültige Weiterleitung von OneDrive.', $a->status);
            }
            $a = $this->http->herunterladen($ziel, $zielDatei, [], $zeit);
        }
        if ($a->status < 200 || $a->status >= 300) {
            throw GraphFehler::ausAntwort($a, 'Datei konnte nicht aus OneDrive geladen werden');
        }
        return $a;
    }

    /** @param list<array<string,mixed>> $liste @param array<string,mixed> $e */
    private function ordnerAnhaengen(array &$liste, array $e, bool $geteilt): void
    {
        // Geteilter Ordner, der über „Zu meinen Dateien hinzufügen“ in der Wurzel liegt.
        if (is_array($e['remoteItem'] ?? null)) {
            $e = $e['remoteItem'];
            $geteilt = true;
        }
        $drive = $e['parentReference']['driveId'] ?? null;
        if (!isset($e['folder']) || !isset($e['id'], $e['name']) || !is_string($drive)) {
            return;
        }
        foreach ($liste as $vorhanden) {
            if ($vorhanden['drive_id'] === $drive && $vorhanden['item_id'] === (string) $e['id']) {
                return;
            }
        }
        $liste[] = ['drive_id' => $drive, 'item_id' => (string) $e['id'], 'name' => (string) $e['name'], 'geteilt' => $geteilt];
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function seiten(string $pfad): \Generator
    {
        $url = self::BASIS . $pfad;
        for ($i = 0; $url !== '' && $i < 50; $i++) {
            $a = $this->anfrageMitToken('GET', $url);
            if ($a->status >= 400) {
                throw GraphFehler::ausAntwort($a, 'OneDrive-Anfrage fehlgeschlagen');
            }
            $d = $a->json();
            foreach ($d['value'] ?? [] as $e) {
                if (is_array($e)) {
                    yield $e;
                }
            }
            $url = (string) ($d['@odata.nextLink'] ?? '');
            if (!str_starts_with($url, self::BASIS)) {
                $url = '';
            }
        }
    }

    /** @param array<string,mixed> $d @return array{item_id:string,pfad:string} */
    private function ergebnis(array $d, string $relPfad): array
    {
        if (!isset($d['id'])) {
            throw new GraphFehler('OneDrive hat die Datei nicht bestätigt.');
        }
        $pfad = $relPfad;
        if (isset($d['name']) && is_string($d['name']) && $d['name'] !== '') {
            $dir = dirname($relPfad);
            $pfad = ($dir === '.' ? '' : $dir . '/') . $d['name'];
        }
        return ['item_id' => (string) $d['id'], 'pfad' => $pfad];
    }

    private function pfadKodiert(string $pfad): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $pfad)));
    }

    /**
     * @param array<string,string> $kopf
     * @return array<string,mixed>
     */
    private function json(string $methode, string $pfad, array $kopf = [], ?string $koerper = null): array
    {
        return $this->sende($methode, $pfad, $kopf, $koerper)->json();
    }

    /** @param array<string,string> $kopf */
    private function sende(string $methode, string $pfad, array $kopf = [], ?string $koerper = null, ?int $zeit = null): HttpAntwort
    {
        $a = $this->anfrageMitToken($methode, self::BASIS . $pfad, $kopf, $koerper, $zeit);
        if ($a->status >= 400) {
            throw GraphFehler::ausAntwort($a, 'OneDrive-Anfrage fehlgeschlagen');
        }
        return $a;
    }

    /** @param array<string,string> $kopf */
    private function anfrageMitToken(string $methode, string $url, array $kopf = [], ?string $koerper = null, ?int $zeit = null): HttpAntwort
    {
        $zeit ??= $this->zeitlimit;
        $a = $this->http->anfrage($methode, $url, $kopf + ['Authorization' => 'Bearer ' . $this->zugriffstoken(false)], $koerper, $zeit);
        if ($a->status === 401) {
            $a = $this->http->anfrage($methode, $url, $kopf + ['Authorization' => 'Bearer ' . $this->zugriffstoken(true)], $koerper, $zeit);
        }
        return $a;
    }

    private function zugriffstoken(bool $erzwingeErneuerung): string
    {
        $t = $this->speicher->lade();
        if ($t === null) {
            throw new GraphFehler('OneDrive ist nicht verbunden.');
        }
        if (!$erzwingeErneuerung && (int) $t['ablauf'] - time() >= self::RESTLAUFZEIT) {
            return (string) $t['access_token'];
        }
        try {
            $neu = $this->oauth->erneuere((string) $t['refresh_token']);
        } catch (GraphFehler $e) {
            if ($e->istAbgelaufen()) {
                $this->speicher->setzeStatus(TokenSpeicher::STATUS_ABGELAUFEN);
            }
            throw $e;
        }
        $this->speicher->speichere($neu);
        return $neu['access_token'];
    }
}
