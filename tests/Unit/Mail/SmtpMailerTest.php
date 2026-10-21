<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Mail;

use Emc\Mail\MailFehler;
use Emc\Mail\Nachricht;
use Emc\Mail\SmtpMailer;
use Emc\Tests\Support\FakeSmtpVerbindung;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    private function mailer(FakeSmtpVerbindung $v, int $port = 465, string $verschluesselung = 'ssl', string $benutzer = 'verein@example.org', string $absender = 'verein@example.org'): SmtpMailer
    {
        return new SmtpMailer($v, 'mail.example.org', $port, $verschluesselung, $benutzer, 'streng-geheim!', $absender, 'EMC 78 Lokschuppen');
    }

    private function nachricht(string $an = 'max@example.org'): Nachricht
    {
        return new Nachricht($an, 'Neues Kennwort für EMC 78 Lokschuppen', "Hallo Max,\n.\nZeile mit Ümlaut");
    }

    public function test_ssl_dialog_mit_anmeldung(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich();
        $this->mailer($v)->sende($this->nachricht());

        $this->assertSame([['host' => 'mail.example.org', 'port' => 465, 'ssl' => true, 'zeitlimit' => 15]], $v->geoeffnet);
        $this->assertSame(0, $v->tlsGestartet);
        $this->assertSame([
            'EHLO example.org',
            'AUTH LOGIN',
            base64_encode('verein@example.org'),
            base64_encode('streng-geheim!'),
            'MAIL FROM:<verein@example.org>',
            'RCPT TO:<max@example.org>',
            'DATA',
            '.',
            'QUIT',
        ], $v->befehle());
        $kopf = implode("\n", $v->nachricht());
        $this->assertStringContainsString('Subject: =?UTF-8?B?', $kopf);
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $kopf);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $kopf);
        $this->assertStringContainsString('From: =?UTF-8?B?' . base64_encode('EMC 78 Lokschuppen') . '?= <verein@example.org>', $kopf);
        $this->assertStringContainsString('To: <max@example.org>', $kopf);
        $this->assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{32}@example\.org>$/m', $kopf);
        $this->assertMatchesRegularExpression('/^Date: \w{3}, \d{2} \w{3} \d{4} /m', $kopf);
        // Körper: base64, keine Zeile beginnt mit einem Punkt, Inhalt wiederherstellbar.
        $zeilen = $v->nachricht();
        $leer = array_search('', $zeilen, true);
        $koerper = array_slice($zeilen, $leer + 1);
        foreach ($zeilen as $z) {
            $this->assertFalse(str_starts_with($z, '.') && !str_starts_with($z, '..'), $z);
            $this->assertLessThanOrEqual(998, strlen($z));
        }
        // Zeilenenden in der kanonischen Form CRLF.
        $this->assertSame("Hallo Max,\r\n.\r\nZeile mit Ümlaut", base64_decode(implode('', $koerper), true));
        $this->assertSame(1, $v->geschlossen);
    }

    public function test_tls_startet_starttls_und_wiederholt_ehlo(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich(tls: true);
        $this->mailer($v, 587, 'tls')->sende($this->nachricht());

        $this->assertFalse($v->geoeffnet[0]['ssl']);
        $this->assertSame(587, $v->geoeffnet[0]['port']);
        $this->assertSame(1, $v->tlsGestartet);
        $befehle = $v->befehle();
        $this->assertSame(['EHLO example.org', 'STARTTLS', 'EHLO example.org', 'AUTH LOGIN'], array_slice($befehle, 0, 4));
    }

    public function test_ohne_benutzer_keine_anmeldung(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich(anmeldung: false);
        $this->mailer($v, benutzer: '')->sende($this->nachricht());
        $this->assertNotContains('AUTH LOGIN', $v->befehle());
    }

    public function test_falsches_kennwort_wirft_ohne_kennwort_in_meldung(): void
    {
        $v = new FakeSmtpVerbindung([[220], [250], [334], [334], [535, 'Authentication failed for streng-geheim!']]);
        try {
            $this->mailer($v)->sende($this->nachricht());
            $this->fail('MailFehler erwartet');
        } catch (MailFehler $e) {
            $this->assertStringContainsString('AUTH', $e->getMessage());
            $this->assertStringContainsString('535', $e->getMessage());
            $this->assertStringNotContainsString('streng-geheim', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode('streng-geheim!'), $e->getMessage());
        }
        $this->assertSame(1, $v->geschlossen);
    }

    public function test_abgelehnter_empfaenger_wirft_und_schliesst(): void
    {
        $v = new FakeSmtpVerbindung([[220], [250], [334], [334], [235], [250], [550, 'No such user max@example.org']]);
        try {
            $this->mailer($v)->sende($this->nachricht());
            $this->fail('MailFehler erwartet');
        } catch (MailFehler $e) {
            $this->assertSame('SMTP: unerwartete Antwort 550 auf RCPT TO', $e->getMessage());
        }
        $this->assertSame(1, $v->geschlossen);
        $this->assertNotContains('DATA', $v->zeilen);
    }

    public function test_zeilenumbruch_im_empfaenger_wirft_vor_verbindung(): void
    {
        foreach (["a@b.de\r\nRCPT TO:<x@y.de>", "a@b.de\nBcc: x@y.de", 'keine-adresse', ''] as $an) {
            $v = FakeSmtpVerbindung::erfolgreich();
            try {
                $this->mailer($v)->sende($this->nachricht($an));
                $this->fail('MailFehler erwartet für ' . json_encode($an));
            } catch (MailFehler) {
                $this->assertSame([], $v->geoeffnet);
                $this->assertSame([], $v->zeilen);
            }
        }
    }

    public function test_ungueltiger_absender_wirft_vor_verbindung(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich();
        try {
            $this->mailer($v, absender: "verein@example.org\r\nBcc: x@y.de")->sende($this->nachricht());
            $this->fail('MailFehler erwartet');
        } catch (MailFehler) {
            $this->assertSame([], $v->geoeffnet);
        }
    }

    public function test_zeilenumbrueche_in_betreff_und_name_werden_entfernt(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich();
        $m = new SmtpMailer($v, 'mail.example.org', 465, 'ssl', 'u', 'p', 'verein@example.org', "EMC\r\nBcc: x@y.de");
        $m->sende(new Nachricht('max@example.org', "Hallo\r\nBcc: x@y.de", 'Text'));
        foreach ($v->nachricht() as $z) {
            $this->assertStringNotContainsString('Bcc:', $z);
            $this->assertStringNotContainsString("\r", $z);
            $this->assertStringNotContainsString("\n", $z);
        }
        $kopf = implode("\n", $v->nachricht());
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('HalloBcc: x@y.de') . '?=', $kopf);
    }

    public function test_langer_betreff_wird_in_mehrere_kodierte_woerter_geteilt(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich();
        $betreff = str_repeat('Kennwort für den Lokschuppen ', 6);
        $this->mailer($v)->sende(new Nachricht('max@example.org', $betreff, 'Text'));
        $zeilen = $v->nachricht();
        $i = array_values(array_filter(array_keys($zeilen), fn($k) => str_starts_with($zeilen[$k], 'Subject: ')))[0];
        $teile = [$zeilen[$i]];
        for ($k = $i + 1; isset($zeilen[$k]) && str_starts_with($zeilen[$k], ' '); $k++) {
            $teile[] = $zeilen[$k];
        }
        $this->assertGreaterThan(1, count($teile));
        $text = '';
        foreach ($teile as $t) {
            $this->assertLessThanOrEqual(78, strlen($t));
            preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $t, $m);
            foreach ($m[1] as $b64) {
                $this->assertLessThanOrEqual(75, strlen('=?UTF-8?B?' . $b64 . '?='));
                $text .= base64_decode($b64, true);
            }
        }
        $this->assertSame(trim($betreff), $text);
    }

    public function test_aus_config_ohne_host_ist_null(): void
    {
        $basis = ['mail_smtp_host' => 'mail.example.org', 'mail_absender' => 'verein@example.org', 'oeffentliche_url' => 'https://verein.example'];
        foreach (['mail_smtp_host', 'mail_absender', 'oeffentliche_url'] as $fehlt) {
            $c = $basis;
            $c[$fehlt] = '  ';
            $this->assertNull(SmtpMailer::ausConfig($c, new FakeSmtpVerbindung()), $fehlt);
            unset($c[$fehlt]);
            $this->assertNull(SmtpMailer::ausConfig($c, new FakeSmtpVerbindung()), $fehlt);
            $this->assertFalse(SmtpMailer::konfiguriert($c));
        }
        $this->assertTrue(SmtpMailer::konfiguriert($basis));
        $this->assertNull(SmtpMailer::ausConfig([], new FakeSmtpVerbindung()));
    }

    public function test_aus_config_standardwerte(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich(anmeldung: false);
        $m = SmtpMailer::ausConfig(['mail_smtp_host' => 'mail.example.org', 'mail_absender' => 'verein@example.org', 'oeffentliche_url' => 'https://verein.example'], $v);
        $this->assertInstanceOf(SmtpMailer::class, $m);
        $m->sende($this->nachricht());
        $this->assertSame([['host' => 'mail.example.org', 'port' => 465, 'ssl' => true, 'zeitlimit' => 15]], $v->geoeffnet);
        $this->assertStringContainsString('From: =?UTF-8?B?' . base64_encode('EMC 78 Lokschuppen') . '?= <verein@example.org>', implode("\n", $v->nachricht()));
    }

    public function test_aus_config_mit_tls_und_port(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich(tls: true);
        $m = SmtpMailer::ausConfig([
            'mail_smtp_host' => 'mail.example.org', 'mail_smtp_port' => 587, 'mail_smtp_verschluesselung' => 'TLS',
            'mail_smtp_benutzer' => 'u', 'mail_smtp_passwort' => 'p', 'mail_absender' => 'verein@example.org',
            'mail_absender_name' => 'Verein', 'oeffentliche_url' => 'https://verein.example',
        ], $v);
        $m->sende($this->nachricht());
        $this->assertSame(587, $v->geoeffnet[0]['port']);
        $this->assertFalse($v->geoeffnet[0]['ssl']);
        $this->assertSame(1, $v->tlsGestartet);
    }

    public function test_unbekannte_verschluesselung_wird_ssl(): void
    {
        $v = FakeSmtpVerbindung::erfolgreich(anmeldung: false);
        SmtpMailer::ausConfig(['mail_smtp_host' => 'h.example.org', 'mail_smtp_verschluesselung' => 'keine', 'mail_absender' => 'verein@example.org', 'oeffentliche_url' => 'https://v.example'], $v)
            ->sende($this->nachricht());
        $this->assertTrue($v->geoeffnet[0]['ssl']);
    }
}
