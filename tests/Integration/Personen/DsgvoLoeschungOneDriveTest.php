<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Personen;

use Emc\Dateien\DokumentRepository;
use Emc\Fahrzeuge\FahrzeugRepository;
use Emc\OneDrive\Auftraege;
use Emc\Personen\DsgvoLoeschung;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\OneDrive\OneDriveTestCase;

/** Nach dem Commit werden die OneDrive-Lösch-Aufträge gleich nebenbei ausgeführt – bei einem Fehler nicht. */
final class DsgvoLoeschungOneDriveTest extends OneDriveTestCase
{
    private int $admin;
    private int $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verbinde();
        $this->admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
        $this->pdo->exec("INSERT INTO person (vorname, nachname) VALUES ('Carla', 'Beispiel')");
        $this->person = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, spurweite_id, eigentuemer_person_id) SELECT 'EMC-0001', 'Lok', id, ? FROM spurweite LIMIT 1")
            ->execute([$this->person]);
        $fz = (int) $this->pdo->lastInsertId();
        $dok = $this->dokument('FOTO', 'jpg', $fz);
        $this->pdo->prepare("UPDATE dokument SET ablage = 'onedrive', onedrive_item_id = 'ITEM-1', onedrive_drive_id = 'D1' WHERE id = ?")->execute([$dok]);
    }

    private function dienst(): DsgvoLoeschung
    {
        return new DsgvoLoeschung(
            $this->pdo,
            new DokumentRepository($this->pdo, $this->speicher, new Auftraege($this->pdo)),
            new Aenderungsprotokoll($this->pdo),
            new FahrzeugRepository($this->pdo),
            new Auftraege($this->pdo),
            $this->uebertragung()
        );
    }

    public function test_nebenbei_nach_dem_commit(): void
    {
        $this->http->antwort(204);

        $this->dienst()->loesche($this->person, [], $this->admin);

        $this->assertCount(1, $this->http->anfragen);
        $this->assertSame('DELETE', $this->http->anfragen[0]['methode']);
        $this->assertStringContainsString('ITEM-1', $this->http->anfragen[0]['url']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM onedrive_auftrag WHERE status = 'wartet'")->fetchColumn());
    }

    public function test_kein_nebenbei_bei_fehler(): void
    {
        $this->pdo->exec("CREATE TRIGGER test_person_sperre BEFORE DELETE ON person FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gesperrt'");
        try {
            try {
                $this->dienst()->loesche($this->person, [], $this->admin);
                $this->fail('Ausnahme erwartet');
            } catch (\PDOException) {
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS test_person_sperre');
        }

        $this->assertSame([], $this->http->anfragen);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM onedrive_auftrag')->fetchColumn());
    }
}
