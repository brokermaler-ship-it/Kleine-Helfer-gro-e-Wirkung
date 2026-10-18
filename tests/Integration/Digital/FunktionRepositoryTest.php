<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Digital;

use Emc\Digital\FunktionRepository;
use Emc\Tests\Integration\DbTestCase;

final class FunktionRepositoryTest extends DbTestCase
{
    public static function neuerDecoder(\PDO $pdo, string $bez = 'Motor'): int
    {
        $sid = (int) $pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $n = (int) $pdo->query('SELECT COUNT(*) FROM fahrzeug')->fetchColumn() + 1;
        $pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id) VALUES (?, ?, ?)')
            ->execute([sprintf('EMC-%04d', $n), 'Lok', $sid]);
        $pdo->prepare('INSERT INTO decoder (fahrzeug_id, bezeichnung) VALUES (?, ?)')->execute([(int) $pdo->lastInsertId(), $bez]);
        return (int) $pdo->lastInsertId();
    }

    public function test_ersetzen_ueberschreibt_belegung(): void
    {
        $d = self::neuerDecoder($this->pdo);
        $repo = new FunktionRepository($this->pdo);
        $repo->ersetzen($d, [
            ['taste' => 0, 'beschreibung' => 'Licht', 'schaltart' => 'dauer'],
            ['taste' => 1, 'beschreibung' => 'Sound', 'schaltart' => 'dauer'],
        ]);
        $this->assertSame([0, 1], array_keys($repo->fuerDecoder($d)));
        $repo->ersetzen($d, [['taste' => 2, 'beschreibung' => 'Pfeife', 'schaltart' => 'moment']]);
        $f = $repo->fuerDecoder($d);
        $this->assertSame([2], array_keys($f));
        $this->assertSame(['taste' => 2, 'beschreibung' => 'Pfeife', 'schaltart' => 'moment'], $f[2]);
    }

    public function test_leere_beschreibung_wird_nicht_gespeichert(): void
    {
        $d = self::neuerDecoder($this->pdo);
        $repo = new FunktionRepository($this->pdo);
        $repo->ersetzen($d, [
            ['taste' => 0, 'beschreibung' => '  ', 'schaltart' => 'dauer'],
            ['taste' => 1, 'beschreibung' => 'Licht', 'schaltart' => 'dauer'],
        ]);
        $this->assertSame([1], array_keys($repo->fuerDecoder($d)));
    }
}
