<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Protokoll;

use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;

final class AenderungsprotokollTest extends DbTestCase
{
    public function test_schreiben_und_lesen_neueste_zuerst_mit_anzeigename(): void
    {
        $bid = $this->legeBenutzerAn('max@verein.de');
        $p = new Aenderungsprotokoll($this->pdo);
        $p->schreibe($bid, 'person', 5, 'angelegt', ['vorname' => ['alt' => null, 'neu' => 'Anna']]);
        $p->schreibe(null, 'person', 5, 'geaendert', ['vorname' => ['alt' => 'Anna', 'neu' => 'Berta']]);
        $p->schreibe($bid, 'person', 6, 'angelegt');

        $liste = $p->fuerObjekt('person', 5);
        $this->assertCount(2, $liste);
        $this->assertSame('geaendert', $liste[0]['aktion']);
        $this->assertNull($liste[0]['anzeigename']);
        $this->assertSame('Max Muster', $liste[1]['anzeigename']);
        $this->assertSame(['vorname' => ['alt' => null, 'neu' => 'Anna']], $liste[1]['aenderungen']);
    }

    /** @return array<string,array{alt:mixed,neu:mixed}> */
    private function aenderungen(int $id): array
    {
        $st = $this->pdo->prepare('SELECT aenderungen FROM aenderungsprotokoll WHERE id = ?');
        $st->execute([$id]);
        return (array) json_decode((string) $st->fetchColumn(), true);
    }

    private function eintrag(Aenderungsprotokoll $p, ?int $benutzerId, string $typ, int $objektId, array $aenderungen): int
    {
        $p->schreibe($benutzerId, $typ, $objektId, 'geaendert', $aenderungen);
        return (int) $this->pdo->lastInsertId();
    }

    private const BEGRIFFE = ['Carla Muster', 'Muster, Carla', 'carla@test.de', '0345 123'];

    public function test_anonymisiere_ersetzt_volle_namen_email_telefon(): void
    {
        $p = new Aenderungsprotokoll($this->pdo);
        $name = $this->eintrag($p, null, 'fahrzeug', 1, ['eigentuemer' => ['alt' => 'Carla Muster', 'neu' => 'Verein']]);
        $mail = $this->eintrag($p, null, 'benutzer', 9, ['email' => ['alt' => null, 'neu' => 'CARLA@test.de']]);
        $tel = $this->eintrag($p, null, 'person', 3, ['telefon' => ['alt' => '0345 123', 'neu' => 'Tel. 0345 123 (mobil)']]);
        $umgedreht = $this->eintrag($p, null, 'fahrzeug', 2, ['eigentuemer' => ['alt' => 'muster, carla', 'neu' => null]]);
        $anders = $this->eintrag($p, null, 'person', 4, ['vorname' => ['alt' => 'Carla Schmidt', 'neu' => 'Carla']]);
        $verschachtelt = $this->eintrag($p, null, 'fahrzeug', 5, ['liste' => ['alt' => ['a' => ['Kontakt: Carla Muster']], 'neu' => [7, true, 'x']]]);

        $anzahl = $p->anonymisiere(self::BEGRIFFE, null, []);

        $this->assertSame(['eigentuemer' => ['alt' => 'ehemaliges Mitglied', 'neu' => 'Verein']], $this->aenderungen($name));
        $this->assertSame(['email' => ['alt' => null, 'neu' => 'ehemaliges Mitglied']], $this->aenderungen($mail));
        $this->assertSame(['telefon' => ['alt' => 'ehemaliges Mitglied', 'neu' => 'Tel. ehemaliges Mitglied (mobil)']], $this->aenderungen($tel));
        $this->assertSame(['eigentuemer' => ['alt' => 'ehemaliges Mitglied', 'neu' => null]], $this->aenderungen($umgedreht));
        $this->assertSame(['vorname' => ['alt' => 'Carla Schmidt', 'neu' => 'Carla']], $this->aenderungen($anders));
        $this->assertSame(['liste' => ['alt' => ['a' => ['Kontakt: ehemaliges Mitglied']], 'neu' => [7, true, 'x']]], $this->aenderungen($verschachtelt));
        $this->assertSame(5, $anzahl);
    }

    public function test_anonymisiere_laengere_begriffe_zuerst_und_nur_werte(): void
    {
        $p = new Aenderungsprotokoll($this->pdo);
        $id = $this->eintrag($p, null, 'fahrzeug', 1, ['Muster' => ['alt' => 'Muster, Carla', 'neu' => 'Jürgen MÜLLER']]);

        $p->anonymisiere(['Muster', 'Muster, Carla', 'jürgen müller', '', '   '], null, []);

        $this->assertSame(['Muster' => ['alt' => 'ehemaliges Mitglied', 'neu' => 'ehemaliges Mitglied']], $this->aenderungen($id));
    }

    public function test_anonymisiere_mit_platzhalterzeichen_im_begriff(): void
    {
        $p = new Aenderungsprotokoll($this->pdo);
        $treffer = $this->eintrag($p, null, 'benutzer', 1, ['email' => ['alt' => 'max_muster%1@test.de', 'neu' => null]]);
        $aehnlich = $this->eintrag($p, null, 'benutzer', 2, ['email' => ['alt' => 'maxXmusterY1@test.de', 'neu' => null]]);

        $this->assertSame(1, $p->anonymisiere(['max_muster%1@test.de'], null, []));

        $this->assertSame(['email' => ['alt' => 'ehemaliges Mitglied', 'neu' => null]], $this->aenderungen($treffer));
        $this->assertSame(['email' => ['alt' => 'maxXmusterY1@test.de', 'neu' => null]], $this->aenderungen($aehnlich));
    }

    public function test_anonymisiere_loescht_eigene_objekte_und_leert_benutzer(): void
    {
        $p = new Aenderungsprotokoll($this->pdo);
        $this->eintrag($p, 5, 'person', 17, ['vorname' => ['alt' => null, 'neu' => 'Carla']]);
        $p->schreibe(null, 'benutzer', 5, 'angelegt');
        $behalten = $this->eintrag($p, 5, 'fahrzeug', 17, ['bezeichnung' => ['alt' => 'V 100', 'neu' => 'V 200']]);
        $fremd = $this->eintrag($p, 6, 'person', 18, ['vorname' => ['alt' => null, 'neu' => 'Dora']]);
        $beides = $this->eintrag($p, 5, 'person', 18, ['notiz' => ['alt' => null, 'neu' => 'von Carla Muster']]);

        $anzahl = $p->anonymisiere(self::BEGRIFFE, 5, [['person', 17], ['benutzer', 5]]);

        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM aenderungsprotokoll WHERE (objekt_typ = 'person' AND objekt_id = 17) OR (objekt_typ = 'benutzer' AND objekt_id = 5)")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM aenderungsprotokoll WHERE benutzer_id = 5')->fetchColumn());
        $this->assertNull($this->pdo->query("SELECT benutzer_id FROM aenderungsprotokoll WHERE id = $behalten")->fetchColumn());
        $this->assertSame(6, (int) $this->pdo->query("SELECT benutzer_id FROM aenderungsprotokoll WHERE id = $fremd")->fetchColumn());
        $this->assertSame(['notiz' => ['alt' => null, 'neu' => 'von ehemaliges Mitglied']], $this->aenderungen($beides));
        // 2 gelöscht, 2 geändert ($behalten, $beides – letzterer nur einmal gezählt)
        $this->assertSame(4, $anzahl);
    }

    public function test_anonymisiere_nur_ganze_woerter(): void
    {
        $p = new Aenderungsprotokoll($this->pdo);
        $laenger = $this->eintrag($p, null, 'person', 1, ['nachname' => ['alt' => 'Carla Mustermann', 'neu' => 'XCarla Muster']]);
        $tel = $this->eintrag($p, null, 'person', 2, ['telefon' => ['alt' => 'Tel. 0345 123 (mobil)', 'neu' => '0345 1234']]);
        $mail = $this->eintrag($p, null, 'benutzer', 3, ['email' => ['alt' => '<carla@test.de>', 'neu' => 'xcarla@test.de']]);

        $p->anonymisiere(self::BEGRIFFE, null, []);

        $this->assertSame(['nachname' => ['alt' => 'Carla Mustermann', 'neu' => 'XCarla Muster']], $this->aenderungen($laenger));
        $this->assertSame(['telefon' => ['alt' => 'Tel. ehemaliges Mitglied (mobil)', 'neu' => '0345 1234']], $this->aenderungen($tel));
        $this->assertSame(['email' => ['alt' => '<ehemaliges Mitglied>', 'neu' => 'xcarla@test.de']], $this->aenderungen($mail));
    }

    public function test_anonymisiere_ignoriert_kurze_und_ungueltige_begriffe(): void
    {
        $p = new Aenderungsprotokoll($this->pdo);
        $id = $this->eintrag($p, null, 'person', 1, ['notiz' => ['alt' => 'am 12.03. mit Bo', 'neu' => 'Carla Muster']]);

        $anzahl = $p->anonymisiere(['12', ' Bo ', 'abc', "Carla Muster\xFF", 'Carla Muster'], null, []);

        $this->assertSame(1, $anzahl);
        $this->assertSame(['notiz' => ['alt' => 'am 12.03. mit Bo', 'neu' => 'ehemaliges Mitglied']], $this->aenderungen($id));
    }
}
