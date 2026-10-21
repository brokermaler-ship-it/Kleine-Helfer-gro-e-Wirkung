<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Boerse;

use Emc\Betrieb\Einstellungen;
use Emc\Boerse\Spamschutz;
use Emc\Tests\Integration\DbTestCase;

/** Fangfeld, signierte Mindestzeit, Begrenzung je IP (nur gehasht) und Aufräumen nach 24 Stunden. */
final class SpamschutzTest extends DbTestCase
{
    private Spamschutz $spam;
    private \DateTimeImmutable $jetzt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spam = new Spamschutz($this->pdo, new Einstellungen($this->pdo));
        $this->jetzt = new \DateTimeImmutable('2026-10-21 12:00:00');
    }

    /** @return array<string,string> */
    private function post(\DateTimeImmutable $ausgefuellt, array $mehr = []): array
    {
        return $mehr + ['zeitmarke' => $this->spam->zeitmarke($ausgefuellt), Spamschutz::FANGFELD => ''];
    }

    public function test_mensch_und_bot(): void
    {
        $this->assertTrue($this->spam->istMensch($this->post($this->jetzt->modify('-3 seconds')), $this->jetzt));
        $this->assertTrue($this->spam->istMensch($this->post($this->jetzt->modify('-23 hours')), $this->jetzt));
        $ohneFang = $this->post($this->jetzt->modify('-10 seconds'));
        unset($ohneFang[Spamschutz::FANGFELD]);
        $this->assertTrue($this->spam->istMensch($ohneFang, $this->jetzt), 'Fangfeld fehlt ganz: kein Bot-Merkmal');

        $this->assertFalse($this->spam->istMensch($this->post($this->jetzt->modify('-2 seconds')), $this->jetzt), 'zu schnell');
        $this->assertFalse($this->spam->istMensch($this->post($this->jetzt->modify('+10 seconds')), $this->jetzt), 'aus der Zukunft');
        $this->assertFalse($this->spam->istMensch($this->post($this->jetzt->modify('-25 hours')), $this->jetzt), 'zu alt');
        $this->assertFalse($this->spam->istMensch($this->post($this->jetzt->modify('-10 seconds'), [Spamschutz::FANGFELD => 'https://spam.example']), $this->jetzt), 'Fangfeld gefüllt');
        $this->assertFalse($this->spam->istMensch($this->post($this->jetzt->modify('-10 seconds'), [Spamschutz::FANGFELD => ['x']]), $this->jetzt), 'Fangfeld als Array');

        // Verfälschte Signatur bzw. andere Zeit mit alter Signatur
        $marke = $this->spam->zeitmarke($this->jetzt->modify('-10 seconds'));
        [$zeit, $sig] = explode('.', $marke);
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => $zeit . '.' . strrev($sig)], $this->jetzt));
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => ((int) $zeit - 3600) . '.' . $sig], $this->jetzt));
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => (string) ((int) $zeit - 3600)], $this->jetzt));
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => ''], $this->jetzt));
        $this->assertFalse($this->spam->istMensch([], $this->jetzt));
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => ['x']], $this->jetzt));
        $this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', $marke);
    }

    public function test_ip_hash(): void
    {
        $hash = $this->spam->ipHash('203.0.113.7');
        $salz = (string) $this->pdo->query("SELECT wert FROM einstellung WHERE schluessel = 'boerse_ip_salz'")->fetchColumn();
        $this->assertSame(64, strlen($salz), 'Salz angelegt');
        $this->assertSame(hash_hmac('sha256', '203.0.113.7', $salz), $hash);
        $this->assertNotSame(hash('sha256', '203.0.113.7'), $hash);
        $this->assertStringNotContainsString('203.0.113.7', $hash);
        $this->assertSame($hash, $this->spam->ipHash('203.0.113.7'), 'gleiches Salz beim zweiten Mal');
        $this->assertNotSame($hash, $this->spam->ipHash('203.0.113.8'));
        // IPv6: ein Anschluss hat meist ein /56-Netz (oder größer) – gezählt wird je /56.
        $this->assertSame('2001:db8:1::/56', Spamschutz::netz('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('2001:db8:1:ff00::/56', Spamschutz::netz('2001:db8:1:ffab::1'));
        $this->assertSame($this->spam->ipHash('2001:db8:1:2::1'), $this->spam->ipHash('2001:0db8:0001:00ff:ffff::9'), 'gleiches /56');
        $this->assertNotSame($this->spam->ipHash('2001:db8:1:2::1'), $this->spam->ipHash('2001:db8:1:100::1'), 'anderes /56');
        $this->assertSame($hash, $this->spam->ipHash('::ffff:203.0.113.7'), 'IPv4 in IPv6-Schreibweise');
        $this->assertSame('', Spamschutz::netz(''));
        // Eigenes Salz, nicht das von „Kennwort vergessen“.
        $this->assertNull((new Einstellungen($this->pdo))->hole('kennwort_ip_salz'));
    }

    public function test_begrenzung_je_stunde(): void
    {
        $ip = $this->spam->ipHash('203.0.113.7');
        for ($i = 0; $i < Spamschutz::MAX_JE_STUNDE; $i++) {
            $this->assertFalse($this->spam->begrenzt($ip, $this->jetzt), "Anfrage $i");
            $this->spam->zaehle($ip, $this->jetzt);
        }
        $this->assertTrue($this->spam->begrenzt($ip, $this->jetzt));
        $this->assertTrue($this->spam->begrenzt($ip, $this->jetzt->modify('+59 minutes')));
        $this->assertFalse($this->spam->begrenzt($ip, $this->jetzt->modify('+61 minutes')));
        $this->assertFalse($this->spam->begrenzt($this->spam->ipHash('203.0.113.8'), $this->jetzt), 'andere IP');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM boerse_anfrage_sperre WHERE ip_hash LIKE '%203.0%'")->fetchColumn());
    }

    /** Obergrenze für die ganze Instanz: auch viele verschiedene IPs zusammen höchstens MAX_GESAMT_JE_STUNDE je Stunde. */
    public function test_globale_obergrenze(): void
    {
        $this->assertSame(60, Spamschutz::MAX_GESAMT_JE_STUNDE);
        for ($i = 0; $i < Spamschutz::MAX_GESAMT_JE_STUNDE; $i++) {
            $this->spam->zaehle($this->spam->ipHash('198.51.100.' . $i), $this->jetzt->modify('-' . ($i % 50) . ' minutes'));
        }
        $neu = $this->spam->ipHash('203.0.113.200');
        $this->assertTrue($this->spam->begrenzt($neu, $this->jetzt), 'neue IP, aber Instanz voll');
        $this->assertFalse($this->spam->begrenzt($neu, $this->jetzt->modify('+51 minutes')), 'eine Stunde später wieder frei');
    }

    /** Die Zeitmarke gilt nur in der Sitzung, für die sie ausgegeben wurde (Bindung an das CSRF-Token). */
    public function test_zeitmarke_an_sitzung_gebunden(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $marke = $this->spam->zeitmarke($this->jetzt->modify('-10 seconds'), $a);
        $this->assertTrue($this->spam->istMensch(['zeitmarke' => $marke], $this->jetzt, $a));
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => $marke], $this->jetzt, $b), 'andere Sitzung');
        $this->assertFalse($this->spam->istMensch(['zeitmarke' => $marke], $this->jetzt, ''), 'ohne Sitzung');
    }

    public function test_aufraeumen_nach_24_stunden(): void
    {
        $ip = $this->spam->ipHash('203.0.113.7');
        $this->spam->zaehle($ip, $this->jetzt->modify('-25 hours'));
        $this->spam->zaehle($ip, $this->jetzt->modify('-23 hours'));
        $this->spam->raeumeAuf($this->jetzt);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM boerse_anfrage_sperre')->fetchColumn());
    }
}
