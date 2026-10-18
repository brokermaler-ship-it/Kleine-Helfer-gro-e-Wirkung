<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Auth;

use Emc\Auth\AnmeldeErgebnis;
use Emc\Auth\Auth;
use Emc\Auth\Rolle;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;

final class AuthTest extends DbTestCase
{
    private array $sitzung = [];

    private function auth(): Auth
    {
        return new Auth($this->pdo, new Session($this->sitzung));
    }

    public function test_anmeldung_erfolgreich(): void
    {
        $this->legeBenutzerAn('max@verein.de', 'geheim-passwort-1', 'bearbeiter');
        $auth = $this->auth();
        $this->assertSame(AnmeldeErgebnis::Erfolg, $auth->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1'));
        $b = $auth->benutzer();
        $this->assertNotNull($b);
        $this->assertSame('max@verein.de', $b->email);
        $this->assertSame('Max Muster', $b->anzeigename);
        $this->assertSame(Rolle::Bearbeiter, $b->rolle);
        $this->assertNotNull($this->pdo->query('SELECT letzter_login FROM benutzer')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_versuch WHERE erfolgreich = 1')->fetchColumn());
    }

    public function test_sitzung_wird_beim_login_erneuert(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $s = new Session($this->sitzung);
        (new Auth($this->pdo, $s))->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1');
        $this->assertSame(1, $s->erneuerungen());
    }

    public function test_email_gross_klein_egal(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $this->assertSame(AnmeldeErgebnis::Erfolg, $this->auth()->anmelden(' Max@Verein.DE ', 'geheim-passwort-1', '127.0.0.1'));
    }

    public function test_falsches_passwort(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $auth = $this->auth();
        $this->assertSame(AnmeldeErgebnis::Falsch, $auth->anmelden('max@verein.de', 'falsch', '127.0.0.1'));
        $this->assertNull($auth->benutzer());
    }

    public function test_unbekannte_email_ist_falsch(): void
    {
        $this->assertSame(AnmeldeErgebnis::Falsch, $this->auth()->anmelden('niemand@verein.de', 'x', '127.0.0.1'));
    }

    public function test_sperre_nach_fuenf_fehlversuchen_in_15_minuten(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(AnmeldeErgebnis::Falsch, $auth->anmelden('max@verein.de', 'falsch', '127.0.0.1'));
        }
        $this->assertSame(AnmeldeErgebnis::Gesperrt, $auth->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1'));
        $this->assertNull($auth->benutzer());
    }

    public function test_sperre_gilt_auch_fuer_unbekannte_email(): void
    {
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->anmelden('niemand@verein.de', 'x', '127.0.0.1');
        }
        $this->assertSame(AnmeldeErgebnis::Gesperrt, $auth->anmelden('niemand@verein.de', 'x', '127.0.0.1'));
    }

    public function test_alte_fehlversuche_zaehlen_nicht(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->anmelden('max@verein.de', 'falsch', '127.0.0.1');
        }
        $this->pdo->exec('UPDATE login_versuch SET zeitpunkt = NOW() - INTERVAL 16 MINUTE');
        $this->assertSame(AnmeldeErgebnis::Erfolg, $auth->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1'));
    }

    public function test_inaktiver_benutzer(): void
    {
        $this->legeBenutzerAn('max@verein.de', 'geheim-passwort-1', 'mitglied', false);
        $auth = $this->auth();
        $this->assertSame(AnmeldeErgebnis::Inaktiv, $auth->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1'));
        $this->assertNull($auth->benutzer());
    }

    public function test_inaktiver_benutzer_mit_falschem_passwort_verraet_nichts(): void
    {
        $this->legeBenutzerAn('max@verein.de', 'geheim-passwort-1', 'mitglied', false);
        $this->assertSame(AnmeldeErgebnis::Falsch, $this->auth()->anmelden('max@verein.de', 'falsch', '127.0.0.1'));
    }

    public function test_abmelden(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $auth = $this->auth();
        $auth->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1');
        $auth->abmelden();
        $this->assertNull($auth->benutzer());
    }

    public function test_deaktivierung_wirkt_in_laufender_sitzung(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $auth = $this->auth();
        $auth->anmelden('max@verein.de', 'geheim-passwort-1', '127.0.0.1');
        $this->pdo->exec('UPDATE benutzer SET aktiv = 0');
        $this->assertNull($this->auth()->benutzer());
    }
}
