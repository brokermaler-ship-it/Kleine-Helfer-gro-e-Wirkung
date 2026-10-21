<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Reparatur;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

/** Hinweis „N neue Defektmeldungen“ (Leiste und Badge am Menüpunkt „Reparaturen“) und Kachel „Offene Defekte“. */
final class HinweisHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $spurweite;
    /** @var array<string,int> Benutzer-IDs je Rolle */
    private array $benutzer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        foreach (['mitglied', 'fahrzeugwart', 'bearbeiter'] as $rolle) {
            $this->benutzer[$rolle] = $this->legeBenutzerAn($rolle . '@verein.de', 'geheim-passwort-1', $rolle);
        }
    }

    private function als(string $rolle): self
    {
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $this->benutzer[$rolle]);
        $this->sitzung['_csrf'] = str_repeat('a', 64);
        return $this;
    }

    private function get(string $pfad, array $query = []): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))->handle(new Request('GET', $pfad, $query));
    }

    private function post(string $pfad, array $daten): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))
            ->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat('a', 64)]));
    }

    private function fahrzeug(string $nummer = 'EMC-0012', string $bezeichnung = 'V 200'): int
    {
        $this->pdo->prepare('INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id) VALUES (?, ?, ?, ?)')
            ->execute([$nummer, $bezeichnung, 'lok', $this->spurweite]);
        return (int) $this->pdo->lastInsertId();
    }

    private function anlage(int $nummer = 3, string $name = 'Clubanlage H0'): int
    {
        $this->pdo->prepare('INSERT INTO anlage (nummer, name, spurweite_id) VALUES (?, ?, ?)')->execute([$nummer, $name, $this->spurweite]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Meldet über das Formular „Defekt melden“ und liefert die ID der neuen Reparatur. */
    private function melde(string $rolle, string $typ, int $objektId, string $beschreibung = 'Licht geht nicht', string $einstufung = 'normal'): int
    {
        $r = $this->als($rolle)->post(($typ === 'anlage' ? '/anlagen/' : '/fahrzeuge/') . $objektId . '/defekt', [
            'beschreibung' => $beschreibung,
            'einstufung' => $einstufung,
        ]);
        self::assertSame(303, $r->status);
        return (int) $this->pdo->query('SELECT MAX(id) FROM reparatur')->fetchColumn();
    }

    /** Text der Hinweisleiste zu neuen Defektmeldungen auf der Startseite, null ohne Leiste. */
    private function hinweis(string $rolle): ?string
    {
        $body = $this->als($rolle)->get('/')->body;
        if (preg_match('~<div class="alert alert-warning" role="alert">\s*(\d+ neue Defektmeldung(?:en)?)\s*<a class="alert-link" href="/reparaturen\?status=gemeldet">Ansehen</a>~', $body, $m) !== 1) {
            self::assertStringNotContainsString('neue Defektmeldung', $body);
            self::assertStringNotContainsString('data-neue-defekte', $body);
            return null;
        }
        // Dieselbe Zahl als Badge am Menüpunkt „Reparaturen“.
        self::assertMatchesRegularExpression('~href="/reparaturen">Reparaturen <span class="badge[^"]*" data-neue-defekte="' . (int) $m[1] . '"~', $body);
        return $m[1];
    }

    public function test_fahrzeugwart_sieht_neue_fahrzeugmeldung(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $this->melde('mitglied', 'fahrzeug', $fz);
        self::assertSame('1 neue Defektmeldung', $this->hinweis('fahrzeugwart'));
        self::assertSame('1 neue Defektmeldung', $this->hinweis('bearbeiter'));

        $this->melde('mitglied', 'anlage', $anl, 'Weiche 4 schaltet nicht');
        self::assertSame('1 neue Defektmeldung', $this->hinweis('fahrzeugwart'));
        self::assertSame('2 neue Defektmeldungen', $this->hinweis('bearbeiter'));

        // Der Link führt auf die Liste mit Filter „gemeldet“.
        $liste = $this->als('bearbeiter')->get('/reparaturen', ['status' => 'gemeldet']);
        self::assertSame(200, $liste->status);
        self::assertStringContainsString('Weiche 4 schaltet nicht', $liste->body);
    }

    public function test_hinweis_auf_jeder_seite_und_als_badge(): void
    {
        $fz = $this->fahrzeug();
        $this->melde('mitglied', 'fahrzeug', $fz);
        $seite = $this->als('fahrzeugwart')->get('/fahrzeuge');
        self::assertStringContainsString('1 neue Defektmeldung', $seite->body);
        self::assertStringContainsString('data-neue-defekte="1"', $seite->body);
        self::assertStringContainsString('<span class="visually-hidden">neue Meldungen</span>', $seite->body);
    }

    public function test_oeffnen_macht_nicht_mehr_neu(): void
    {
        $fz = $this->fahrzeug();
        $anl = $this->anlage();
        $rFz = $this->melde('mitglied', 'fahrzeug', $fz);
        $rAnl = $this->melde('mitglied', 'anlage', $anl, 'Weiche 4 schaltet nicht');

        // Ein Mitglied (ohne Rechte) öffnet: bleibt neu.
        self::assertSame(200, $this->als('mitglied')->get("/reparaturen/$rFz")->status);
        self::assertSame('1 neue Defektmeldung', $this->hinweis('fahrzeugwart'));
        // Ein Fahrzeugwart öffnet die Anlagenmeldung: für den Bearbeiter bleibt sie neu.
        self::assertSame(200, $this->als('fahrzeugwart')->get("/reparaturen/$rAnl")->status);
        self::assertSame('2 neue Defektmeldungen', $this->hinweis('bearbeiter'));

        // Der Fahrzeugwart öffnet die Fahrzeugmeldung: schon auf der Detailseite kein Hinweis mehr.
        $detail = $this->als('fahrzeugwart')->get("/reparaturen/$rFz");
        self::assertSame(200, $detail->status);
        self::assertStringNotContainsString('neue Defektmeldung', $detail->body);
        self::assertNull($this->hinweis('fahrzeugwart'));
        self::assertSame('1 neue Defektmeldung', $this->hinweis('bearbeiter'));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reparatur_gesehen WHERE reparatur_id = $rFz")->fetchColumn());

        // Erneutes Öffnen legt keine zweite Zeile an.
        $this->als('fahrzeugwart')->get("/reparaturen/$rFz");
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reparatur_gesehen WHERE reparatur_id = $rFz")->fetchColumn());

        // Der Bearbeiter öffnet die Anlagenmeldung: nichts mehr neu.
        $this->als('bearbeiter')->get("/reparaturen/$rAnl");
        self::assertNull($this->hinweis('bearbeiter'));
    }

    public function test_statuswechsel_macht_nicht_mehr_neu(): void
    {
        $fz = $this->fahrzeug();
        $rid = $this->melde('mitglied', 'fahrzeug', $fz);
        self::assertSame('1 neue Defektmeldung', $this->hinweis('fahrzeugwart'));
        $this->pdo->exec("UPDATE reparatur SET status = 'geplant' WHERE id = $rid");
        self::assertNull($this->hinweis('fahrzeugwart'));
        self::assertNull($this->hinweis('bearbeiter'));
    }

    public function test_eigene_meldung_zaehlt_nicht(): void
    {
        $fz = $this->fahrzeug();
        $this->melde('fahrzeugwart', 'fahrzeug', $fz);
        self::assertNull($this->hinweis('fahrzeugwart'));
        self::assertSame('1 neue Defektmeldung', $this->hinweis('bearbeiter'));

        // Meldung eines ehemaligen Mitglieds (Benutzer gelöscht) zählt für alle.
        $this->pdo->exec('UPDATE reparatur SET gemeldet_von_benutzer_id = NULL');
        self::assertSame('1 neue Defektmeldung', $this->hinweis('fahrzeugwart'));
    }

    public function test_mitglied_sieht_keinen_hinweis(): void
    {
        $fz = $this->fahrzeug();
        $this->melde('fahrzeugwart', 'fahrzeug', $fz);
        $this->melde('bearbeiter', 'anlage', $this->anlage());
        self::assertNull($this->hinweis('mitglied'));
    }

    public function test_startseite_offene_defekte(): void
    {
        $normal = $this->fahrzeug('EMC-0012', 'V 200');
        $dringend = $this->fahrzeug('EMC-0013', 'BR 50');
        $rNormal = $this->melde('mitglied', 'fahrzeug', $normal, 'Ruckelt etwas');
        $this->pdo->exec("UPDATE reparatur SET gemeldet_am = '2026-10-01 10:00:00' WHERE id = $rNormal");
        $rDringend = $this->melde('mitglied', 'fahrzeug', $dringend, 'Bleibt stehen', 'dringend');

        $body = $this->als('mitglied')->get('/')->body;
        self::assertStringContainsString('Offene Defekte', $body);
        self::assertMatchesRegularExpression('~>\s*2 offen\s*<~', $body);
        self::assertMatchesRegularExpression('~>\s*1 dringend\s*<~', $body);
        $kachel = substr($body, (int) strpos($body, 'id="kachel-defekte"'));
        $kachel = substr($kachel, 0, (int) strpos($kachel, '</section>'));
        self::assertStringContainsString('href="/fahrzeuge/' . $dringend . '"', $kachel);
        self::assertStringContainsString('href="/fahrzeuge/' . $normal . '"', $kachel);
        self::assertLessThan(strpos($kachel, 'V 200'), strpos($kachel, 'BR 50'), 'dringende zuerst');
        self::assertStringContainsString('F13', $kachel);
        self::assertStringContainsString('gemeldet', $kachel);
        self::assertStringContainsString('href="/reparaturen"', $kachel);
        self::assertStringContainsString('Alle Reparaturen', $kachel);
        // Vor der Wartungs-Kachel; Schnellzugriff „Defekt melden“.
        self::assertLessThan(strpos($body, 'id="kachel-wartung"'), strpos($body, 'id="kachel-defekte"'));
        self::assertStringContainsString('href="/defekt">Defekt melden</a>', substr($body, (int) strpos($body, 'id="kachel-schnell"')));

        $this->pdo->exec("UPDATE reparatur SET status = 'erledigt' WHERE id IN ($rNormal, $rDringend)");
        $body = $this->als('mitglied')->get('/')->body;
        self::assertStringContainsString('Keine offenen Defekte.', $body);
        self::assertDoesNotMatchRegularExpression('~\d+ offen\s*<~', $body);
    }

    public function test_kachel_zeigt_hoechstens_fuenf(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $fz = $this->fahrzeug(sprintf('EMC-%04d', 20 + $i), 'Lok ' . $i);
            $this->melde('mitglied', 'fahrzeug', $fz, 'Defekt ' . $i, $i === 7 ? 'dringend' : 'normal');
            $this->pdo->exec("UPDATE reparatur SET gemeldet_am = '2026-10-0$i 10:00:00' WHERE id = (SELECT MAX(id) FROM (SELECT id FROM reparatur) x)");
        }
        $body = $this->als('mitglied')->get('/')->body;
        $kachel = substr($body, (int) strpos($body, 'id="kachel-defekte"'));
        $kachel = substr($kachel, 0, (int) strpos($kachel, '</section>'));
        self::assertMatchesRegularExpression('~>\s*7 offen\s*<~', $kachel);
        self::assertSame(5, substr_count($kachel, '<li'));
        // Dringende zuerst, dann die älteste Meldung.
        self::assertLessThan(strpos($kachel, 'Lok 1<'), strpos($kachel, 'Lok 7<'));
        self::assertLessThan(strpos($kachel, 'Lok 2<'), strpos($kachel, 'Lok 1<'));
        self::assertStringNotContainsString('Lok 5<', $kachel);
        self::assertStringNotContainsString('Lok 6<', $kachel);
    }
}
