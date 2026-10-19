<?php

declare(strict_types=1);

namespace Emc\OneDrive;

/** Produktive HTTP-Schnittstelle auf Basis von ext-curl; folgt keinen Weiterleitungen. */
final class CurlHttpClient implements HttpClient
{
    public function anfrage(string $methode, string $url, array $kopf = [], ?string $koerper = null, int $zeitlimit = 20): HttpAntwort
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new GraphFehler('HTTP-Verbindung konnte nicht vorbereitet werden.');
        }
        $zeilen = [];
        foreach ($kopf as $name => $wert) {
            $zeilen[] = $name . ': ' . $wert;
        }
        $antwortKopf = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $zeilen,
            CURLOPT_TIMEOUT => max(1, $zeitlimit),
            CURLOPT_CONNECTTIMEOUT => min(10, max(1, $zeitlimit)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $zeile) use (&$antwortKopf): int {
                if (str_starts_with($zeile, 'HTTP/')) {
                    $antwortKopf = [];
                    return strlen($zeile);
                }
                $teile = explode(':', $zeile, 2);
                if (count($teile) === 2) {
                    $antwortKopf[strtolower(trim($teile[0]))] = trim($teile[1]);
                }
                return strlen($zeile);
            },
        ]);
        if ($koerper !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $koerper);
        }
        $inhalt = curl_exec($ch);
        if ($inhalt === false) {
            $fehler = curl_error($ch);
            curl_close($ch);
            // Keine URL/Header in die Meldung: sie könnten Zugangsdaten enthalten.
            throw new GraphFehler('OneDrive ist nicht erreichbar (' . $fehler . ').');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new HttpAntwort($status, $antwortKopf, (string) $inhalt);
    }
}
