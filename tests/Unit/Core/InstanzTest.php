<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\Instanz;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstanzTest extends TestCase
{
    public function test_testumgebung_nur_bei_echtem_true(): void
    {
        $this->assertTrue(Instanz::istTestumgebung(['testumgebung' => true]));
        $this->assertFalse(Instanz::istTestumgebung([]));
        $this->assertFalse(Instanz::istTestumgebung(['testumgebung' => false]));
    }

    /** @return array<string,array{mixed}> */
    public static function keinTrue(): array
    {
        return ['String false' => ['false'], 'String true' => ['true'], 'Zahl 1' => [1], 'String 1' => ['1'], 'String ja' => ['ja'], 'null' => [null]];
    }

    #[DataProvider('keinTrue')]
    public function test_andere_werte_schalten_testumgebung_nicht_ein(mixed $wert): void
    {
        $this->assertFalse(Instanz::istTestumgebung(['testumgebung' => $wert]));
    }

    public function test_fingerabdruck_haengt_an_datenbank_und_basis_url(): void
    {
        $a = ['db_dsn' => 'mysql:host=db1;dbname=emc78', 'basis_url' => ''];
        $this->assertSame(hash('sha256', 'mysql:host=db1;dbname=emc78|'), Instanz::fingerabdruck($a));
        $this->assertSame(Instanz::fingerabdruck($a), Instanz::fingerabdruck(['db_dsn' => 'mysql:host=db1;dbname=emc78']));
        $this->assertNotSame(Instanz::fingerabdruck($a), Instanz::fingerabdruck(['db_dsn' => 'mysql:host=db1;dbname=emc78_test'] + $a));
        $this->assertNotSame(Instanz::fingerabdruck($a), Instanz::fingerabdruck(['basis_url' => '/mitglieder'] + $a));
    }

    public function test_sitzungsname_und_cookie_pfad_je_instanz(): void
    {
        $a = ['db_dsn' => 'mysql:host=db1;dbname=emc78'];
        $b = ['db_dsn' => 'mysql:host=db1;dbname=emc78_test'];
        $this->assertSame('emc78_' . substr(Instanz::fingerabdruck($a), 0, 8), Instanz::sitzungsName($a));
        $this->assertNotSame(Instanz::sitzungsName($a), Instanz::sitzungsName($b));
        $this->assertSame('/', Instanz::cookiePfad($a));
        $this->assertSame('/', Instanz::cookiePfad(['basis_url' => '']));
        $this->assertSame('/mitglieder', Instanz::cookiePfad(['basis_url' => '/mitglieder/']));
    }

    public function test_sitzungsdauer_standard_acht_stunden(): void
    {
        $this->assertSame(28800, Instanz::sitzungSekunden([]));
        $this->assertSame(3600, Instanz::sitzungSekunden(['sitzung_minuten' => 60]));
        $this->assertSame(28800, Instanz::sitzungSekunden(['sitzung_minuten' => 0]));
        $this->assertSame(28800, Instanz::sitzungSekunden(['sitzung_minuten' => 'viel']));
    }
}
