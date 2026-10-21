<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\Antwortlink;
use PHPUnit\Framework\TestCase;

/** mailto:/tel:-Links aus nicht vertrauenswürdigen Eingaben des öffentlichen Bereichs. */
final class AntwortlinkTest extends TestCase
{
    public function test_betreff(): void
    {
        $this->assertSame('Deine Anfrage an die Modellbahnbörse (B2026-118)', Antwortlink::betreff(['art' => 'kaufanfrage', 'angebot_nummer' => 'B2026-118']));
        $this->assertSame('Deine Anfrage an die Modellbahnbörse', Antwortlink::betreff(['art' => 'kaufanfrage', 'angebot_nummer' => null]));
        $this->assertSame('Deine Sammlung – Anfrage an die Modellbahnbörse', Antwortlink::betreff(['art' => 'sammlung']));
    }

    public function test_mailto_kodiert(): void
    {
        $this->assertSame(
            'mailto:erika@test.emc78.de?subject=' . rawurlencode('Deine Anfrage an die Modellbahnbörse (B2026-118)'),
            Antwortlink::mailto('erika@test.emc78.de', 'Deine Anfrage an die Modellbahnbörse (B2026-118)')
        );
        $this->assertSame(
            'mailto:erika.b%2Bboerse@test.emc78.de?subject=Hallo%20Welt&body=Hallo%20Eve%2C%0D%0A%0D%0A',
            Antwortlink::mailto('erika.b+boerse@test.emc78.de', 'Hallo Welt', Antwortlink::anrede('Eve'))
        );
        $link = (string) Antwortlink::mailto('a@test.emc78.de', "Betreff\r\nBcc: boese@example.org", Antwortlink::anrede("Eve\r\nBcc: x@y.de"));
        $this->assertStringNotContainsString("\n", $link);
        $this->assertStringNotContainsString("\r", $link);
        $this->assertStringContainsString('?subject=Betreff%20Bcc%3A%20boese%40example.org&body=', $link);
        $this->assertStringContainsString('body=Hallo%20Eve%20Bcc%3A%20x%40y.de%2C%0D%0A%0D%0A', $link);
        $this->assertSame(1, substr_count($link, '?'));
        $this->assertSame(1, substr_count($link, '&'));
    }

    public function test_mailto_nur_gueltige_adresse(): void
    {
        foreach ([null, '', 'kaputt', 'a@@b.de', "a@b.de\r\nBcc: c@d.de", 'a@b.de?cc=c@d.de', 'javascript:alert(1)//@b.de', '<a@b.de>', "o'hara@test.emc78.de", 'a?cc=x&bcc=spy@evil.example', 'a%0Abcc=x@evil.example', 'a@localhost', str_repeat('a', 180) . '@test.emc78.de'] as $email) {
            $this->assertNull(Antwortlink::mailto($email, 'x'), (string) $email);
        }
    }

    public function test_tel(): void
    {
        $this->assertSame('tel:0621123456', Antwortlink::tel('0621 123456'));
        $this->assertSame('tel:+4962112345', Antwortlink::tel('+49 (0) 621/123-45'));
        $this->assertSame('tel:+49621123', Antwortlink::tel('+49 621 123'));
        $this->assertNull(Antwortlink::tel(null));
        $this->assertNull(Antwortlink::tel('12'));
        $this->assertNull(Antwortlink::tel('javascript:alert()'));
    }
}
