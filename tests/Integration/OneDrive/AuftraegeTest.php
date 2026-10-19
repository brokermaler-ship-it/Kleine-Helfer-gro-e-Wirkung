<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\OneDrive;

use Emc\OneDrive\Auftraege;
use Emc\Tests\Integration\DbTestCase;

final class AuftraegeTest extends DbTestCase
{
    private Auftraege $a;
    private \DateTimeImmutable $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = new Auftraege($this->pdo);
        $this->t0 = new \DateTimeImmutable('2026-10-19 12:00:00');
    }

    private function zeile(int $id): array
    {
        return $this->pdo->query('SELECT * FROM onedrive_auftrag WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
    }

    public function testNeuUndBeanspruchen(): void
    {
        $id = $this->a->neu('hochladen', 5, null, $this->t0);
        $z = $this->zeile($id);
        self::assertSame('wartet', $z['status']);
        self::assertSame('2026-10-19 12:00:00', $z['naechster_versuch']);
        $l = $this->a->beanspruche(5, $this->t0);
        self::assertCount(1, $l);
        self::assertSame($id, (int) $l[0]['id']);
        self::assertSame('hochladen', $l[0]['art']);
        self::assertSame('2026-10-19 12:10:00', $this->zeile($id)['gesperrt_bis']);
    }

    public function testBeanspruchungSchliesstDoppelverarbeitungAus(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->a->neu('hochladen', $i + 1, null, $this->t0);
        }
        $erste = $this->a->beanspruche(2, $this->t0);
        $zweite = $this->a->beanspruche(5, $this->t0);
        $dritte = $this->a->beanspruche(5, $this->t0);
        self::assertCount(2, $erste);
        self::assertCount(1, $zweite);
        self::assertSame([], $dritte);
        $ids = array_merge(array_column($erste, 'id'), array_column($zweite, 'id'));
        self::assertCount(3, array_unique($ids));
    }

    public function testSperreLaeuftNachZehnMinutenAb(): void
    {
        $this->a->neu('hochladen', 1, null, $this->t0);
        self::assertCount(1, $this->a->beanspruche(5, $this->t0));
        self::assertSame([], $this->a->beanspruche(5, $this->t0->modify('+9 minutes')));
        self::assertCount(1, $this->a->beanspruche(5, $this->t0->modify('+11 minutes')));
    }

    public function testErledigtUndZaehle(): void
    {
        $id = $this->a->neu('hochladen', 1, null, $this->t0);
        $this->a->neu('loeschen', null, 'ITEM', $this->t0);
        $this->a->beanspruche(1, $this->t0);
        $this->a->erledigt($id);
        self::assertSame(['wartet' => 1, 'fehler' => 0, 'erledigt' => 1], $this->a->zaehle());
        self::assertSame([], array_filter($this->a->beanspruche(5, $this->t0->modify('+1 hour')), fn($z) => (int) $z['id'] === $id));
    }

    public function testBackoffZeiten(): void
    {
        $id = $this->a->neu('hochladen', 1, null, $this->t0);
        $erwartet = [60, 300, 1800, 7200, 3600, 3600];
        foreach ($erwartet as $i => $sek) {
            $this->a->fehlgeschlagen($id, 'kaputt', null, $this->t0);
            $z = $this->zeile($id);
            self::assertSame($i + 1, (int) $z['versuche']);
            self::assertSame($this->t0->modify("+$sek seconds")->format('Y-m-d H:i:s'), $z['naechster_versuch'], 'Versuch ' . ($i + 1));
            self::assertSame('wartet', $z['status']);
            self::assertNull($z['gesperrt_bis']);
            self::assertSame('kaputt', $z['letzter_fehler']);
        }
    }

    public function testRetryAfterWirdBeachtet(): void
    {
        $id = $this->a->neu('hochladen', 1, null, $this->t0);
        $this->a->fehlgeschlagen($id, 'x', 900, $this->t0);
        self::assertSame('2026-10-19 12:15:00', $this->zeile($id)['naechster_versuch']);
    }

    public function testNachZwanzigVersuchenFehler(): void
    {
        $id = $this->a->neu('hochladen', 1, null, $this->t0);
        for ($i = 0; $i < 20; $i++) {
            $this->a->fehlgeschlagen($id, str_repeat('ü', 600), null, $this->t0);
        }
        $z = $this->zeile($id);
        self::assertSame('fehler', $z['status']);
        self::assertSame(20, (int) $z['versuche']);
        self::assertSame(500, mb_strlen($z['letzter_fehler']));
        self::assertSame(1, $this->a->zaehle()['fehler']);
    }

    public function testFreigebenZaehltNichtAlsVersuch(): void
    {
        $id = $this->a->neu('hochladen', 1, null, $this->t0);
        $this->a->beanspruche(1, $this->t0);
        $this->a->freigeben($id, 'abgelaufen', 600, $this->t0);
        $z = $this->zeile($id);
        self::assertSame(0, (int) $z['versuche']);
        self::assertNull($z['gesperrt_bis']);
        self::assertSame('2026-10-19 12:10:00', $z['naechster_versuch']);
    }
}
