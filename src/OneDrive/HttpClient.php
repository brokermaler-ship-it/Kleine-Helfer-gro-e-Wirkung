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
}
