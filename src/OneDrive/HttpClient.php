<?php

declare(strict_types=1);

namespace Emc\OneDrive;

interface HttpClient
{
    /**
     * @param array<string,string> $kopf Anfrage-Header (Name => Wert)
     * @throws GraphFehler bei Verbindungsproblemen (wiederholbar)
     */
    public function anfrage(string $methode, string $url, array $kopf = [], ?string $koerper = null, int $zeitlimit = 20): HttpAntwort;

    /**
     * GET, dessen Antwortkörper direkt in $zielDatei geschrieben wird (kein Puffern im Arbeitsspeicher).
     * Die zurückgegebene Antwort hat einen leeren Körper.
     *
     * @param array<string,string> $kopf
     * @throws GraphFehler bei Verbindungsproblemen (wiederholbar)
     */
    public function herunterladen(string $url, string $zielDatei, array $kopf = [], int $zeitlimit = 120): HttpAntwort;
}
