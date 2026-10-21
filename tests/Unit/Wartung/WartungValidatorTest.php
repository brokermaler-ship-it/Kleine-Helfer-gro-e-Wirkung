<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Wartung;

use Emc\Wartung\WartungValidator;
use PHPUnit\Framework\TestCase;

final class WartungValidatorTest extends TestCase
{
    private const HEUTE = '2026-10-21';

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function pruefe(array $u = [], bool $mitStunden = true): array
    {
        $eingabe = $u + ['datum' => '2026-10-20', 'arten' => ['1', '2'], 'betriebsstunden' => '', 'ausgefuehrt_von' => '7', 'notiz' => ''];
        return WartungValidator::pruefe($eingabe, [1, 2, 5], [7, 8], $mitStunden, self::HEUTE);
    }

    public function test_gueltige_eingabe(): void
    {
        [$d, $f] = $this->pruefe(['notiz' => '  Räder sauber  ']);
        $this->assertSame([], $f);
        $this->assertSame('2026-10-20', $d['datum']);
        $this->assertSame([1, 2], $d['arten']);
        $this->assertNull($d['betriebsstunden']);
        $this->assertSame(7, $d['ausgefuehrt_von_person_id']);
        $this->assertSame('Räder sauber', $d['notiz']);
    }

    public function test_heute_ist_erlaubt_und_deutsches_format(): void
    {
        [$d, $f] = $this->pruefe(['datum' => '21.10.2026']);
        $this->assertSame([], $f);
        $this->assertSame('2026-10-21', $d['datum']);
    }

    public function test_keine_art(): void
    {
        [, $f] = $this->pruefe(['arten' => []]);
        $this->assertSame('Bitte mindestens eine Wartungsart wählen.', $f['arten']);
        [, $f] = $this->pruefe(['arten' => 'kein-array']);
        $this->assertSame('Bitte mindestens eine Wartungsart wählen.', $f['arten']);
    }

    public function test_fremde_art(): void
    {
        [, $f] = $this->pruefe(['arten' => ['1', '3']]);
        $this->assertSame('Diese Wartungsart passt nicht zu diesem Objekt.', $f['arten']);
        [, $f] = $this->pruefe(['arten' => ['abc']]);
        $this->assertSame('Diese Wartungsart passt nicht zu diesem Objekt.', $f['arten']);
    }

    public function test_doppelte_art_zaehlt_einmal(): void
    {
        [$d, $f] = $this->pruefe(['arten' => ['5', '5']]);
        $this->assertSame([], $f);
        $this->assertSame([5], $d['arten']);
    }

    public function test_datum(): void
    {
        [, $f] = $this->pruefe(['datum' => '2026-10-22']);
        $this->assertSame('Das Datum darf nicht in der Zukunft liegen.', $f['datum']);
        foreach (['', '2026-02-30', 'gestern', '31.02.2026'] as $falsch) {
            [, $f] = $this->pruefe(['datum' => $falsch]);
            $this->assertSame('Bitte ein gültiges Datum angeben.', $f['datum'], $falsch);
        }
    }

    public function test_stunden(): void
    {
        [, $f] = $this->pruefe(['betriebsstunden' => '-1']);
        $this->assertSame('Bitte einen Stundenstand ab 0 angeben.', $f['betriebsstunden']);
        [, $f] = $this->pruefe(['betriebsstunden' => '12,25']);
        $this->assertSame('Bitte einen Stundenstand ab 0 angeben.', $f['betriebsstunden']);
        [, $f] = $this->pruefe(['betriebsstunden' => 'viel']);
        $this->assertArrayHasKey('betriebsstunden', $f);
        [$d, $f] = $this->pruefe(['betriebsstunden' => '12,5']);
        $this->assertSame([], $f);
        $this->assertSame(12.5, $d['betriebsstunden']);
        [$d] = $this->pruefe(['betriebsstunden' => '30']);
        $this->assertSame(30.0, $d['betriebsstunden']);
        [$d] = $this->pruefe(['betriebsstunden' => '0.0']);
        $this->assertSame(0.0, $d['betriebsstunden']);
    }

    public function test_ohne_stunden_wird_stand_ignoriert(): void
    {
        [$d, $f] = $this->pruefe(['betriebsstunden' => '-1'], false);
        $this->assertSame([], $f);
        $this->assertNull($d['betriebsstunden']);
    }

    public function test_person(): void
    {
        [, $f] = $this->pruefe(['ausgefuehrt_von' => '9']);
        $this->assertSame('Bitte auswählen, wer die Wartung ausgeführt hat.', $f['ausgefuehrt_von']);
        [, $f] = $this->pruefe(['ausgefuehrt_von' => '']);
        $this->assertArrayHasKey('ausgefuehrt_von', $f);
    }

    public function test_notiz_hoechstens_2000_zeichen(): void
    {
        [, $f] = $this->pruefe(['notiz' => str_repeat('ä', 2001)]);
        $this->assertSame('Bitte höchstens 2000 Zeichen eingeben.', $f['notiz']);
        [$d, $f] = $this->pruefe(['notiz' => str_repeat('ä', 2000)]);
        $this->assertSame([], $f);
        [$d] = $this->pruefe(['notiz' => '   ']);
        $this->assertNull($d['notiz']);
    }
}
