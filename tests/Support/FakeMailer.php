<?php

declare(strict_types=1);

namespace Emc\Tests\Support;

use Emc\Mail\MailFehler;
use Emc\Mail\Mailer;
use Emc\Mail\Nachricht;

/** Sammelt Nachrichten statt sie zu senden; mit $scheitert wirft jeder Versand MailFehler. */
final class FakeMailer implements Mailer
{
    /** @var list<Nachricht> */
    public array $gesendet = [];
    public int $versuche = 0;

    public function __construct(public bool $scheitert = false)
    {
    }

    public function sende(Nachricht $n): void
    {
        $this->versuche++;
        if ($this->scheitert) {
            throw new MailFehler('SMTP: unerwartete Antwort 550 auf RCPT TO');
        }
        $this->gesendet[] = $n;
    }

    /** Token aus dem Link der letzten Nachricht. */
    public function letzterToken(): ?string
    {
        $n = end($this->gesendet);
        if ($n === false || preg_match('~/kennwort/neu\?t=([0-9a-f]{64})~', $n->text, $m) !== 1) {
            return null;
        }
        return $m[1];
    }
}
