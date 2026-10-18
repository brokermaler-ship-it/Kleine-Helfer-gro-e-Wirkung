<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\View;
use PHPUnit\Framework\TestCase;

final class LayoutPwaTest extends TestCase
{
    private function rendere(bool $test, string $basis = '/mitglieder'): string
    {
        $GLOBALS['emc_basis_url'] = $basis;
        $view = new View(dirname(__DIR__, 3) . '/templates');
        $view->teile('app_name', 'EMC 78 Halle – Fahrzeugverwaltung');
        $view->teile('testumgebung', $test);
        return $view->render('fehler/404', ['titel' => 'Nicht gefunden']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['emc_basis_url'] = '';
    }

    public function test_layout_enthaelt_pwa_angaben_mit_basis_url(): void
    {
        $html = $this->rendere(false);
        $this->assertStringContainsString('<link rel="manifest" href="/mitglieder/manifest.webmanifest">', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('href="/mitglieder/assets/icons/apple-touch-icon.png"', $html);
        $this->assertStringContainsString('<meta name="theme-color"', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="EMC 78">', $html);
    }

    public function test_testumgebung_leiste_nur_wenn_aktiv(): void
    {
        $this->assertStringContainsString('Testumgebung – Daten können jederzeit zurückgesetzt werden.', $this->rendere(true));
        $this->assertStringNotContainsString('Testumgebung', $this->rendere(false));
    }

    public function test_manifest_ist_gueltig_und_relativ(): void
    {
        $m = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/public/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('EMC 78 Halle – Fahrzeugverwaltung', $m['name']);
        $this->assertSame('EMC 78', $m['short_name']);
        $this->assertSame('./', $m['start_url']);
        $this->assertSame('./', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('de', $m['lang']);
        $groessen = array_column($m['icons'], 'sizes');
        $this->assertContains('192x192', $groessen);
        $this->assertContains('512x512', $groessen);
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(dirname(__DIR__, 3) . '/public/' . $icon['src']);
        }
    }
}
