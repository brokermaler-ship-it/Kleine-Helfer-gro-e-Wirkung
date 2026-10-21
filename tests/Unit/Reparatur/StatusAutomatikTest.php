<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Reparatur;

use Emc\Reparatur\ReparaturStatus;
use Emc\Reparatur\StatusAutomatik;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusAutomatikTest extends TestCase
{
    private const DRINGEND = ['prioritaet' => 'dringend', 'status' => 'gemeldet'];
    private const NORMAL = ['prioritaet' => 'normal', 'status' => 'gemeldet'];
    private const IN_ARBEIT = ['prioritaet' => 'normal', 'status' => 'in_arbeit'];

    /** @return array<string, array{string, string, bool, list<array{prioritaet:string,status:string}>, ?array{status:string,automatisch:bool}}> */
    public static function faelle(): array
    {
        return [
            'dringend offen, einsatzbereit → defekt' => ['fahrzeug', 'einsatzbereit', false, [self::DRINGEND], ['status' => 'defekt', 'automatisch' => true]],
            'nur in Arbeit → in Wartung' => ['fahrzeug', 'einsatzbereit', false, [self::IN_ARBEIT], ['status' => 'in_wartung', 'automatisch' => true]],
            'Anlage nur in Arbeit → in_arbeit' => ['anlage', 'in_ordnung', false, [self::IN_ARBEIT], ['status' => 'in_arbeit', 'automatisch' => true]],
            'Anlage dringend → defekt' => ['anlage', 'in_ordnung', false, [self::DRINGEND], ['status' => 'defekt', 'automatisch' => true]],
            'dringend hat Vorrang vor in Arbeit' => ['fahrzeug', 'einsatzbereit', false, [self::IN_ARBEIT, self::DRINGEND], ['status' => 'defekt', 'automatisch' => true]],
            'dringend und in Arbeit in einer Reparatur' => ['fahrzeug', 'in_wartung', true, [['prioritaet' => 'dringend', 'status' => 'in_arbeit']], ['status' => 'defekt', 'automatisch' => true]],
            'offen, normal, gemeldet → nichts' => ['fahrzeug', 'einsatzbereit', false, [self::NORMAL], null],
            'keine offenen, automatisch defekt → einsatzbereit' => ['fahrzeug', 'defekt', true, [], ['status' => 'einsatzbereit', 'automatisch' => false]],
            'keine offenen, automatisch in Wartung → einsatzbereit' => ['fahrzeug', 'in_wartung', true, [], ['status' => 'einsatzbereit', 'automatisch' => false]],
            'keine offenen, von Hand in Wartung → nichts' => ['fahrzeug', 'in_wartung', false, [], null],
            'keine offenen, von Hand defekt → nichts' => ['fahrzeug', 'defekt', false, [], null],
            'dringend, ausgeliehen → nichts' => ['fahrzeug', 'ausgeliehen', false, [self::DRINGEND], null],
            'dringend, ausgemustert → nichts' => ['fahrzeug', 'ausgemustert', false, [self::DRINGEND], null],
            'geschützt auch mit Kennzeichen' => ['fahrzeug', 'ausgeliehen', true, [], null],
            'dringend, von Hand defekt → nichts' => ['fahrzeug', 'defekt', false, [self::DRINGEND], null],
            'dringend, automatisch defekt → nichts' => ['fahrzeug', 'defekt', true, [self::DRINGEND], null],
            'dringend, von Hand in Wartung → defekt' => ['fahrzeug', 'in_wartung', false, [self::DRINGEND], ['status' => 'defekt', 'automatisch' => true]],
            'Herabstufung: offen normal, automatisch defekt → einsatzbereit' => ['fahrzeug', 'defekt', true, [self::NORMAL], ['status' => 'einsatzbereit', 'automatisch' => false]],
            'automatisch defekt, nur noch in Arbeit → in Wartung' => ['fahrzeug', 'defekt', true, [self::IN_ARBEIT], ['status' => 'in_wartung', 'automatisch' => true]],
            'Anlage keine offenen, automatisch defekt → in_ordnung' => ['anlage', 'defekt', true, [], ['status' => 'in_ordnung', 'automatisch' => false]],
            'Anlage keine offenen, von Hand in_arbeit → nichts' => ['anlage', 'in_arbeit', false, [], null],
            'keine offenen, nicht automatisch, einsatzbereit → nichts' => ['fahrzeug', 'einsatzbereit', false, [], null],
        ];
    }

    /** @param list<array{prioritaet:string,status:string}> $offene */
    #[DataProvider('faelle')]
    public function test_ziel(string $typ, string $aktuell, bool $automatisch, array $offene, ?array $erwartet): void
    {
        self::assertSame($erwartet, StatusAutomatik::ziel($typ, $aktuell, $automatisch, $offene));
    }

    public function test_reparatur_status(): void
    {
        self::assertSame('in Arbeit', ReparaturStatus::InArbeit->label());
        self::assertSame('wartet auf Teile', ReparaturStatus::WartetAufTeile->label());
        self::assertSame(['gemeldet', 'geplant', 'in Arbeit', 'wartet auf Teile', 'erledigt', 'verworfen'], array_map(fn(ReparaturStatus $s) => $s->label(), ReparaturStatus::cases()));
        $offen = array_values(array_filter(ReparaturStatus::cases(), fn(ReparaturStatus $s) => $s->istOffen()));
        self::assertSame([ReparaturStatus::Gemeldet, ReparaturStatus::Geplant, ReparaturStatus::InArbeit, ReparaturStatus::WartetAufTeile], $offen);
        self::assertSame("('gemeldet','geplant','in_arbeit','wartet_auf_teile')", ReparaturStatus::OFFEN_SQL);
    }
}
