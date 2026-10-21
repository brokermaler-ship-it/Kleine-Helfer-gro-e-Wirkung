<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Wartung;

use Emc\Wartung\Ampel;
use Emc\Wartung\Faelligkeitsrechner;
use PHPUnit\Framework\TestCase;

final class FaelligkeitsrechnerTest extends TestCase
{
    private static function d(string $datum): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datum);
    }

    public function test_noch_nie_erfasst_ist_gelb(): void
    {
        $f = Faelligkeitsrechner::berechne(6, 20, null, null, 40.0, self::d('2026-10-21'));
        self::assertSame(Ampel::Gelb, $f->ampel);
        self::assertTrue($f->nochNieErfasst);
        self::assertNull($f->faelligAm);
        self::assertNull($f->faelligBeiStunden);
        self::assertSame(0.0, $f->rest);
        self::assertSame('noch nie erfasst', $f->text());
    }

    public function test_monate(): void
    {
        $letzte = self::d('2026-01-15');
        $ampel = fn(string $heute): Ampel => Faelligkeitsrechner::berechne(6, null, $letzte, null, null, self::d($heute))->ampel;
        self::assertSame('2026-07-15', Faelligkeitsrechner::berechne(6, null, $letzte, null, null, self::d('2026-06-14'))->faelligAm?->format('Y-m-d'));
        self::assertSame(Ampel::Gruen, $ampel('2026-06-14'));
        self::assertSame(Ampel::Gelb, $ampel('2026-06-15'));
        self::assertSame(Ampel::Gelb, $ampel('2026-07-15'));
        self::assertSame(Ampel::Rot, $ampel('2026-07-16'));
        self::assertFalse(Faelligkeitsrechner::berechne(6, null, $letzte, null, null, self::d('2026-07-16'))->nochNieErfasst);
    }

    public function test_monatsende(): void
    {
        self::assertSame('2027-02-28', Faelligkeitsrechner::plusMonate(self::d('2026-08-31'), 6)->format('Y-m-d'));
        self::assertSame('2028-02-29', Faelligkeitsrechner::plusMonate(self::d('2027-08-31'), 6)->format('Y-m-d'));
        self::assertSame('2026-02-28', Faelligkeitsrechner::plusMonate(self::d('2026-03-31'), -1)->format('Y-m-d'));
        self::assertSame('2027-01-15', Faelligkeitsrechner::plusMonate(self::d('2026-01-15'), 12)->format('Y-m-d'));
        // Stichtag am Monatsende: am 28.02.2027 gelb, ab dem 01.03.2027 rot.
        self::assertSame(Ampel::Gelb, Faelligkeitsrechner::berechne(6, null, self::d('2026-08-31'), null, null, self::d('2027-02-28'))->ampel);
        self::assertSame(Ampel::Rot, Faelligkeitsrechner::berechne(6, null, self::d('2026-08-31'), null, null, self::d('2027-03-01'))->ampel);
    }

    public function test_stunden(): void
    {
        $heute = self::d('2026-10-21');
        $ampel = fn(float $aktuell): Ampel => Faelligkeitsrechner::berechne(null, 20, self::d('2026-10-01'), 100.0, $aktuell, $heute)->ampel;
        self::assertSame(Ampel::Gruen, $ampel(117.9));
        self::assertSame(Ampel::Gelb, $ampel(118.0));
        self::assertSame(Ampel::Gelb, $ampel(120.0));
        self::assertSame(Ampel::Rot, $ampel(120.1));
        self::assertSame(120.0, Faelligkeitsrechner::berechne(null, 20, self::d('2026-10-01'), 100.0, 110.0, $heute)->faelligBeiStunden);
        self::assertNull(Faelligkeitsrechner::berechne(null, 20, self::d('2026-10-01'), 100.0, 110.0, $heute)->faelligAm);
    }

    public function test_zuerst_erreicht(): void
    {
        // Zeit grün (vor einer Woche), Stunden rot.
        $f = Faelligkeitsrechner::berechne(6, 20, self::d('2026-10-14'), 100.0, 125.0, self::d('2026-10-21'));
        self::assertSame(Ampel::Rot, $f->ampel);
        self::assertSame('2027-04-14', $f->faelligAm?->format('Y-m-d'));
        self::assertSame(120.0, $f->faelligBeiStunden);
        // Zeit rot, Stunden grün.
        $f = Faelligkeitsrechner::berechne(6, 20, self::d('2026-01-01'), 100.0, 101.0, self::d('2026-10-21'));
        self::assertSame(Ampel::Rot, $f->ampel);
        // Zeit grün, Stunden gelb.
        self::assertSame(Ampel::Gelb, Faelligkeitsrechner::berechne(6, 20, self::d('2026-10-14'), 100.0, 119.0, self::d('2026-10-21'))->ampel);
    }

    public function test_nur_monate_und_anlage_ohne_stunden(): void
    {
        // Ohne aktuellen Stundenstand (Anlage) zählt nur die Zeit.
        $f = Faelligkeitsrechner::berechne(3, 20, self::d('2026-10-01'), 100.0, null, self::d('2026-10-21'));
        self::assertSame(Ampel::Gruen, $f->ampel);
        // Intervall nur in Stunden, aber kein Stundenstand bei der letzten Wartung: nichts zu prüfen → grün.
        $f = Faelligkeitsrechner::berechne(null, 20, self::d('2020-01-01'), null, 500.0, self::d('2026-10-21'));
        self::assertSame(Ampel::Gruen, $f->ampel);
        self::assertNull($f->faelligAm);
        self::assertNull($f->faelligBeiStunden);
    }

    public function test_rest_ordnet_dringlichkeit(): void
    {
        $heute = self::d('2026-10-21');
        $zehn = Faelligkeitsrechner::berechne(6, null, Faelligkeitsrechner::plusMonate($heute->modify('-10 days'), -6), null, null, $heute);
        $zwei = Faelligkeitsrechner::berechne(6, null, Faelligkeitsrechner::plusMonate($heute->modify('-2 days'), -6), null, null, $heute);
        self::assertSame(Ampel::Rot, $zehn->ampel);
        self::assertSame(Ampel::Rot, $zwei->ampel);
        self::assertLessThan($zwei->rest, $zehn->rest);
        self::assertLessThan(0.0, $zwei->rest);
        $gruen = Faelligkeitsrechner::berechne(6, null, $heute, null, null, $heute);
        self::assertEqualsWithDelta(1.0, $gruen->rest, 0.0001);
        // Über beide Kriterien zählt der kleinere Rest.
        $beide = Faelligkeitsrechner::berechne(6, 20, $heute, 100.0, 115.0, $heute);
        self::assertEqualsWithDelta(0.25, $beide->rest, 0.0001);
    }

    public function test_ampel_texte_und_farben(): void
    {
        self::assertSame([0, 1, 2], [Ampel::Rot->rang(), Ampel::Gelb->rang(), Ampel::Gruen->rang()]);
        self::assertSame(['danger', 'warning', 'success'], [Ampel::Rot->farbe(), Ampel::Gelb->farbe(), Ampel::Gruen->farbe()]);
        self::assertSame(['überfällig', 'bald fällig', 'in Ordnung'], [Ampel::Rot->text(), Ampel::Gelb->text(), Ampel::Gruen->text()]);
    }
}
