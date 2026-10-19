<?php

declare(strict_types=1);

namespace Emc\Tests\Support;

use Emc\OneDrive\HttpAntwort;
use Emc\OneDrive\HttpClient;

/** Zeichnet Anfragen auf und liefert vorbereitete Antworten (keine echten Netzwerkaufrufe). */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{methode:string,url:string,kopf:array<string,string>,koerper:?string}> */
    public array $anfragen = [];
    /** @var list<HttpAntwort|callable> */
    private array $antworten = [];

    /** @param array<string,mixed>|string $koerper */
    public function antwort(int $status, array|string $koerper = '', array $kopf = []): self
    {
        $this->antworten[] = new HttpAntwort($status, array_change_key_case($kopf, CASE_LOWER), is_array($koerper) ? json_encode($koerper) : $koerper);
        return $this;
    }

    public function anfrage(string $methode, string $url, array $kopf = [], ?string $koerper = null, int $zeitlimit = 20): HttpAntwort
    {
        $this->anfragen[] = ['methode' => $methode, 'url' => $url, 'kopf' => $kopf, 'koerper' => $koerper];
        if ($this->antworten === []) {
            throw new \LogicException('Keine Antwort vorbereitet für ' . $methode . ' ' . $url);
        }
        $a = array_shift($this->antworten);
        return $a instanceof HttpAntwort ? $a : $a($methode, $url, $kopf, $koerper);
    }
}
