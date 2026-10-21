<?php

declare(strict_types=1);

namespace Emc\Tests\Support;

use Emc\Mail\MailFehler;
use Emc\Mail\SmtpVerbindung;

/** SMTP-Gegenstelle ohne Netz: liefert vorbereitete Antworten und sammelt die geschriebenen Zeilen. */
final class FakeSmtpVerbindung implements SmtpVerbindung
{
    /** @var list<string> */
    public array $zeilen = [];
    /** @var list<array{host:string,port:int,ssl:bool,zeitlimit:int}> */
    public array $geoeffnet = [];
    public int $tlsGestartet = 0;
    public int $geschlossen = 0;
    /** @var list<array{code:int,text:string}> */
    private array $antworten;

    /** @param list<array{0:int,1?:string}> $antworten */
    public function __construct(array $antworten = [])
    {
        $this->antworten = array_map(fn(array $a): array => ['code' => $a[0], 'text' => $a[1] ?? ''], $antworten);
    }

    /** Antworten für einen vollständigen erfolgreichen Dialog. */
    public static function erfolgreich(bool $tls = false, bool $anmeldung = true): self
    {
        $a = [[220, 'mail.example.org ESMTP'], [250, "mail.example.org\nAUTH LOGIN PLAIN"]];
        if ($tls) {
            array_push($a, [220, 'Ready to start TLS'], [250, 'mail.example.org']);
        }
        if ($anmeldung) {
            array_push($a, [334, 'VXNlcm5hbWU6'], [334, 'UGFzc3dvcmQ6'], [235, 'ok']);
        }
        array_push($a, [250, 'ok'], [250, 'ok'], [354, 'go'], [250, 'queued'], [221, 'bye']);
        return new self($a);
    }

    public function oeffne(string $host, int $port, bool $ssl, int $zeitlimit): void
    {
        $this->geoeffnet[] = ['host' => $host, 'port' => $port, 'ssl' => $ssl, 'zeitlimit' => $zeitlimit];
    }

    public function schreibe(#[\SensitiveParameter] string $zeile): void
    {
        $this->zeilen[] = $zeile;
    }

    public function liesAntwort(): array
    {
        if ($this->antworten === []) {
            throw new MailFehler('SMTP: keine Antwort');
        }
        return array_shift($this->antworten);
    }

    public function starteTls(): void
    {
        $this->tlsGestartet++;
    }

    public function schliesse(): void
    {
        $this->geschlossen++;
    }

    /** Geschriebene SMTP-Befehle (ohne die Zeilen der Nachricht zwischen DATA und dem abschließenden Punkt). */
    public function befehle(): array
    {
        $befehle = [];
        $inDaten = false;
        foreach ($this->zeilen as $z) {
            if ($inDaten) {
                if ($z === '.') {
                    $inDaten = false;
                    $befehle[] = $z;
                }
                continue;
            }
            $befehle[] = $z;
            if ($z === 'DATA') {
                $inDaten = true;
            }
        }
        return $befehle;
    }

    /** Zeilen der Nachricht (zwischen DATA und Punkt). */
    public function nachricht(): array
    {
        $start = array_search('DATA', $this->zeilen, true);
        $ende = array_search('.', $this->zeilen, true);
        return $start === false || $ende === false ? [] : array_slice($this->zeilen, $start + 1, $ende - $start - 1);
    }
}
