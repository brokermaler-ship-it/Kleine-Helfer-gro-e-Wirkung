<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\OeffentlicherBereich;
use PHPUnit\Framework\TestCase;

final class OeffentlicherBereichTest extends TestCase
{
    public function test_aktiv_nur_mit_true_und_https(): void
    {
        $this->assertTrue(OeffentlicherBereich::aktiv(['boerse_oeffentlich' => true, 'datenschutz_url' => 'https://www.emc78.de/datenschutz']));
        $this->assertTrue(OeffentlicherBereich::aktiv(['boerse_oeffentlich' => true, 'datenschutz_url' => '  https://www.emc78.de/datenschutz ']));
        foreach (['true', 1, '1', 'ja', null, false] as $schalter) {
            $this->assertFalse(OeffentlicherBereich::aktiv(['boerse_oeffentlich' => $schalter, 'datenschutz_url' => 'https://www.emc78.de/datenschutz']), var_export($schalter, true));
        }
        foreach (['', 'http://www.emc78.de/datenschutz', 'https://', 'javascript:alert(1)', ' ', null, ['https://x.de']] as $url) {
            $this->assertFalse(OeffentlicherBereich::aktiv(['boerse_oeffentlich' => true, 'datenschutz_url' => $url]), var_export($url, true));
        }
        $this->assertFalse(OeffentlicherBereich::aktiv(['boerse_oeffentlich' => true]));
        $this->assertFalse(OeffentlicherBereich::aktiv([]));
        $this->assertFalse(OeffentlicherBereich::aktiv(['boerse_oeffentlich' => true, 'datenschutz_url' => "https://www.emc78.de/\"><script>"]));
    }

    public function test_datenschutz_url(): void
    {
        $this->assertSame('https://www.emc78.de/datenschutz', OeffentlicherBereich::datenschutzUrl(['datenschutz_url' => ' https://www.emc78.de/datenschutz ']));
        $this->assertNull(OeffentlicherBereich::datenschutzUrl(['datenschutz_url' => 'http://www.emc78.de/datenschutz']));
    }

    public function test_ist_pfad(): void
    {
        $this->assertTrue(OeffentlicherBereich::istPfad('/boerse'));
        $this->assertTrue(OeffentlicherBereich::istPfad('/boerse/danke'));
        $this->assertTrue(OeffentlicherBereich::istPfad('/boerse/angebot/B2026-1'));
        $this->assertFalse(OeffentlicherBereich::istPfad('/boersen'));
        $this->assertFalse(OeffentlicherBereich::istPfad('/boersen/1/kasse'));
        $this->assertFalse(OeffentlicherBereich::istPfad('/boerseX'));
        $this->assertFalse(OeffentlicherBereich::istPfad('/'));
    }
}
