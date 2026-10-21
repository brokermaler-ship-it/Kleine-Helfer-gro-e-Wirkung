<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Boerse;

use Emc\Boerse\Abrechnung;
use PHPUnit\Framework\TestCase;

/** Abrechnung einer Börse in ganzen Cent: Verein, Zahlarten, je Mitglied, Kontrollsumme. */
final class AbrechnungTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function pos(string $preis, string $provision, string $herkunft, ?int $person, ?string $name, bool $spende = false, string $zahlart = 'bar'): array
    {
        return ['preis' => $preis, 'provision' => $provision, 'herkunft' => $herkunft, 'person_id' => $person, 'person_name' => $name, 'erloes_spende' => $spende, 'zahlart' => $zahlart];
    }

    public function test_verein_mitglieder_und_kontrolle(): void
    {
        $r = Abrechnung::berechne([
            self::pos('20.00', '0.00', 'verein', null, null),
            self::pos('12.50', '1.25', 'mitglied', 7, 'Carla Beispiel', false, 'karte_ueberweisung'),
            self::pos('0.05', '0.01', 'mitglied', 7, 'Carla Beispiel'),
            self::pos('8.00', '0.00', 'mitglied', 9, 'Bernd Anders', true),
        ]);

        $this->assertSame(['vereinsstuecke' => 2000, 'provisionen' => 126, 'spenden' => 800, 'gesamt' => 2926], $r['verein']);
        $this->assertSame(['bar' => 2805, 'karte_ueberweisung' => 1250], $r['zahlarten']);
        $this->assertSame([
            ['person_id' => 9, 'name' => 'Bernd Anders', 'stuecke' => 1, 'erloes' => 800, 'provision' => 0, 'auszahlung' => 0, 'spende' => 800],
            ['person_id' => 7, 'name' => 'Carla Beispiel', 'stuecke' => 2, 'erloes' => 1255, 'provision' => 126, 'auszahlung' => 1129, 'spende' => 0],
        ], $r['mitglieder']);
        $this->assertTrue($r['kontrolle']);
    }

    public function test_null_prozent_auszahlung_gleich_erloes(): void
    {
        $r = Abrechnung::berechne([self::pos('12.50', '0.00', 'mitglied', 7, 'Carla Beispiel'), self::pos('3.35', '0.00', 'mitglied', 7, 'Carla Beispiel')]);
        $this->assertSame(1585, $r['mitglieder'][0]['erloes']);
        $this->assertSame(0, $r['mitglieder'][0]['provision']);
        $this->assertSame(1585, $r['mitglieder'][0]['auszahlung']);
        $this->assertSame(['vereinsstuecke' => 0, 'provisionen' => 0, 'spenden' => 0, 'gesamt' => 0], $r['verein']);
        $this->assertTrue($r['kontrolle']);
    }

    public function test_hundert_prozent(): void
    {
        $r = Abrechnung::berechne([self::pos('10.00', '10.00', 'mitglied', 7, 'Carla Beispiel')]);
        $this->assertSame(0, $r['mitglieder'][0]['auszahlung']);
        $this->assertSame(1000, $r['verein']['provisionen']);
        $this->assertTrue($r['kontrolle']);
    }

    public function test_ehemalige_mitglieder_zusammengefasst(): void
    {
        $r = Abrechnung::berechne([
            self::pos('5.00', '0.50', 'mitglied', null, null),
            self::pos('7.00', '0.70', 'mitglied', null, null, false, 'karte_ueberweisung'),
            self::pos('1.00', '0.10', 'mitglied', 3, 'Anton Muster'),
        ]);
        $this->assertCount(2, $r['mitglieder']);
        $this->assertSame('Anton Muster', $r['mitglieder'][0]['name']);
        $this->assertSame(['person_id' => null, 'name' => 'ehemaliges Mitglied', 'stuecke' => 2, 'erloes' => 1200, 'provision' => 120, 'auszahlung' => 1080, 'spende' => 0], $r['mitglieder'][1]);
        $this->assertSame(['bar' => 600, 'karte_ueberweisung' => 700], $r['zahlarten']);
        $this->assertTrue($r['kontrolle']);
    }

    public function test_leer(): void
    {
        $r = Abrechnung::berechne([]);
        $this->assertSame(['vereinsstuecke' => 0, 'provisionen' => 0, 'spenden' => 0, 'gesamt' => 0], $r['verein']);
        $this->assertSame(['bar' => 0, 'karte_ueberweisung' => 0], $r['zahlarten']);
        $this->assertSame([], $r['mitglieder']);
        $this->assertTrue($r['kontrolle']);
    }

    public function test_sortierung_nach_name_ohne_gross_klein(): void
    {
        $r = Abrechnung::berechne([
            self::pos('1.00', '0.00', 'mitglied', 1, 'erika Zander'),
            self::pos('1.00', '0.00', 'mitglied', 2, 'Ännchen Adler'),
            self::pos('1.00', '0.00', 'mitglied', 3, 'Dora Berg'),
        ]);
        $this->assertSame(['Ännchen Adler', 'Dora Berg', 'erika Zander'], array_column($r['mitglieder'], 'name'));
    }

    public function test_falsche_eingabe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Abrechnung::berechne([self::pos('1.00', '0.00', 'mitglied', 1, 'A', false, 'scheck')]);
    }
}
