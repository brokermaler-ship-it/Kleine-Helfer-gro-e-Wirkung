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
        $view->teile('app_name', 'EMC 78 Lokschuppen');
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
        $this->assertStringContainsString('<meta name="theme-color" content="#E30311">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-capable" content="yes">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Lokschuppen">', $html);
    }

    public function test_titel_navigation_und_fusszeile_tragen_den_vereinsnamen(): void
    {
        $html = $this->rendere(false);
        $this->assertStringContainsString('<title>Nicht gefunden – EMC 78 Lokschuppen</title>', $html);
        $this->assertStringContainsString('Eisenbahn Modellbahn Club 78 e.V. Halle · <span class="slogan">Die Modellbahnfamilie</span>', $html);
        $this->assertStringContainsString('<footer', $html);
        $this->assertStringContainsString('src="/mitglieder/assets/marke/logo-128.png"', $html);
        $this->assertStringContainsString('>Lokschuppen</span>', $html);
    }

    public function test_css_hat_vereinsfarben_als_variablen(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/app.css');
        $this->assertStringContainsString('--emc-rot: #E30311;', $css);
        $this->assertStringContainsString('--emc-rot-dunkel: #B8020E;', $css);
        $this->assertStringContainsString('--emc-schwarz: #1B1B18;', $css);
        $this->assertStringContainsString('--emc-grau: #C6C6C6;', $css);
        $this->assertStringContainsString('--emc-grau-hell: #F4F4F4;', $css);
    }

    public function test_testumgebung_leiste_nur_wenn_aktiv(): void
    {
        $this->assertStringContainsString('Testumgebung – Daten können jederzeit zurückgesetzt werden.', $this->rendere(true));
        $this->assertStringNotContainsString('Testumgebung', $this->rendere(false));
    }

    public function test_manifest_ist_gueltig_und_relativ(): void
    {
        $m = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/public/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('EMC 78 Lokschuppen', $m['name']);
        $this->assertSame('Lokschuppen', $m['short_name']);
        $this->assertSame('#E30311', $m['theme_color']);
        $this->assertSame('#FFFFFF', strtoupper($m['background_color']));
        $this->assertSame('./', $m['start_url']);
        $this->assertSame('./', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('de', $m['lang']);
        $groessen = array_column($m['icons'], 'sizes');
        $this->assertContains('192x192', $groessen);
        $this->assertContains('512x512', $groessen);
        $maskable = array_filter($m['icons'], fn($i) => ($i['purpose'] ?? '') === 'maskable');
        $this->assertCount(1, $maskable);
        $this->assertSame('assets/icons/icon-maskable-512.png', array_values($maskable)[0]['src']);
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(dirname(__DIR__, 3) . '/public/' . $icon['src']);
        }
    }
}
