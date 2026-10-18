<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Core\DomainFehler;
use Emc\Digital\FunktionRepository;
use Emc\Digital\FunktionsvorlageRepository;
use Emc\Tests\Integration\DbTestCase;

final class FunktionsvorlageRepositoryTest extends DbTestCase
{
    private function belegt(int $d): void
    {
        (new FunktionRepository($this->pdo))->ersetzen($d, [
            ['taste' => 0, 'beschreibung' => 'Licht', 'schaltart' => 'dauer'],
            ['taste' => 1, 'beschreibung' => 'Fahrgeräusch', 'schaltart' => 'dauer'],
            ['taste' => 2, 'beschreibung' => 'Pfeife', 'schaltart' => 'moment'],
        ]);
    }

    public function test_vorlage_von_decoder_auf_anderen_uebertragen(): void
    {
        $a = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $b = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $this->belegt($a);
        $repo = new FunktionsvorlageRepository($this->pdo);
        $v = $repo->ausDecoder($a, 'ESU LokSound V 200', 'LokSound 5');
        (new FunktionRepository($this->pdo))->ersetzen($b, [['taste' => 9, 'beschreibung' => 'Alt', 'schaltart' => 'dauer']]);
        $repo->aufDecoderAnwenden($v, $b);
        $this->assertSame(
            (new FunktionRepository($this->pdo))->fuerDecoder($a),
            (new FunktionRepository($this->pdo))->fuerDecoder($b)
        );
        $alle = $repo->alle();
        $this->assertCount(1, $alle);
        $this->assertSame('ESU LokSound V 200', $alle[0]['name']);
    }

    public function test_vorlagenname_eindeutig(): void
    {
        $a = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $this->belegt($a);
        $repo = new FunktionsvorlageRepository($this->pdo);
        $repo->ausDecoder($a, 'Doppelt', null);
        $this->expectException(DomainFehler::class);
        $this->expectExceptionMessage('Eine Vorlage mit diesem Namen gibt es bereits.');
        $repo->ausDecoder($a, 'Doppelt', null);
    }

    public function test_loeschen(): void
    {
        $a = FunktionRepositoryTest::neuerDecoder($this->pdo);
        $this->belegt($a);
        $repo = new FunktionsvorlageRepository($this->pdo);
        $v = $repo->ausDecoder($a, 'X', null);
        $repo->loeschen($v);
        $this->assertSame([], $repo->alle());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM vorlage_funktion')->fetchColumn());
    }
}
