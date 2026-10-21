<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Auth;

use Emc\Auth\KennwortVergessen;
use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Mail\Mailer;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\FakeMailer;

final class KennwortHttpTest extends DbTestCase
{
    private const CSRF = 'c';

    /** @var array<string,mixed> */
    private array $sitzung = [];
    private ?FakeMailer $mailer = null;

    private string|false $fehlerLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Gescheiterter Versand schreibt ins error_log; in Tests nicht auf die Konsole.
        $this->fehlerLog = ini_set('error_log', '/dev/null');
        $this->sitzung = ['_csrf' => str_repeat(self::CSRF, 64)];
    }

    /** @param array<string,mixed> $sitzung */
    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->fehlerLog);
    }

    private function app(array &$sitzung, array $config = []): App
    {
        $app = App::erzeuge($config + self::testConfig(), new Session($sitzung));
        if ($this->mailer !== null) {
            $app->container()->set(Mailer::class, fn() => $this->mailer);
        }
        $app->container()->get('router')->add('GET', '/fahrzeuge', fn() => new Response('Liste'));
        return $app;
    }

    private function get(string $pfad, array $query = [], array $config = []): Response
    {
        return $this->app($this->sitzung, $config)->handle(new Request('GET', $pfad, $query, [], [], ['REMOTE_ADDR' => '203.0.113.5']));
    }

    private function post(string $pfad, array $daten = [], array $config = []): Response
    {
        return $this->app($this->sitzung, $config)->handle(new Request('POST', $pfad, [], $daten + ['_csrf' => str_repeat(self::CSRF, 64)], [], ['REMOTE_ADDR' => '203.0.113.5']));
    }

    /** @return list<string> */
    private function meldungen(array $sitzung): array
    {
        return array_map(fn($m) => $m['text'], $sitzung['_meldungen'] ?? []);
    }

    public function test_link_auf_anmeldeseite_und_seite_oeffentlich(): void
    {
        $login = $this->get('/login');
        $this->assertStringContainsString('Kennwort vergessen?', $login->body);
        $this->assertStringContainsString('href="/kennwort/vergessen"', $login->body);

        $seite = $this->get('/kennwort/vergessen');
        $this->assertSame(200, $seite->status);
        $this->assertStringContainsString('name="email"', $seite->body);
        $this->assertStringContainsString('name="_csrf"', $seite->body);
        $this->assertStringNotContainsString(KennwortVergessen::ANTWORT, $seite->body);

        $neu = $this->get('/kennwort/neu', ['t' => str_repeat('0', 64)]);
        $this->assertSame(200, $neu->status);
    }

    public function test_ohne_csrf_419(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $r = $this->app($this->sitzung)->handle(new Request('POST', '/kennwort/vergessen', [], ['email' => 'max@verein.de']));
        $this->assertSame(419, $r->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_versuch')->fetchColumn());
    }

    public function test_angemeldete_werden_weitergeleitet(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->sitzungAnmelden($this->sitzung, $id);
        $r = $this->get('/kennwort/vergessen');
        $this->assertSame(303, $r->status);
        $this->assertSame('/', $r->headers['Location']);
        $this->assertSame(303, $this->post('/kennwort/vergessen', ['email' => 'max@verein.de'])->status);
    }

    public function test_antwort_immer_gleich(): void
    {
        $this->mailer = new FakeMailer();
        $this->legeBenutzerAn('max@verein.de');
        $this->legeBenutzerAn('alt@verein.de', aktiv: false);

        $bekannt = $this->post('/kennwort/vergessen', ['email' => 'max@verein.de']);
        $this->assertCount(1, $this->mailer->gesendet);
        $antworten = [
            'bekannt' => $bekannt,
            'unbekannt' => $this->post('/kennwort/vergessen', ['email' => 'niemand@verein.de']),
            'deaktiviert' => $this->post('/kennwort/vergessen', ['email' => 'alt@verein.de']),
            'leer' => $this->post('/kennwort/vergessen', ['email' => '']),
        ];
        $this->post('/kennwort/vergessen', ['email' => 'max@verein.de']);
        $this->post('/kennwort/vergessen', ['email' => 'max@verein.de']);
        $this->assertCount(3, $this->mailer->gesendet);
        $antworten['begrenzt'] = $this->post('/kennwort/vergessen', ['email' => 'max@verein.de']);
        $this->assertCount(3, $this->mailer->gesendet);
        $this->mailer->scheitert = true;
        $this->legeBenutzerAn('pia@verein.de');
        $antworten['versand gescheitert'] = $this->post('/kennwort/vergessen', ['email' => 'pia@verein.de']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_anfrage')->fetchColumn());

        foreach ($antworten as $fall => $r) {
            $this->assertSame(200, $r->status, $fall);
            $this->assertStringContainsString(e(KennwortVergessen::ANTWORT), $r->body, $fall);
            $this->assertSame($bekannt->body, $r->body, $fall);
            $this->assertSame($bekannt->headers, $r->headers, $fall);
        }
        $this->assertStringNotContainsString('max@verein.de', $bekannt->body);
    }

    /** Mit echtem SMTP-Versand antwortet die Seite nie schneller als die Mindestdauer – auch für unbekannte Adressen. */
    public function test_mindestdauer_nur_mit_echtem_postfach(): void
    {
        $mail = ['mail_smtp_host' => 'mail.example.org', 'mail_absender' => 'verein@example.org', 'oeffentliche_url' => 'https://verein.example'];
        $dauer = fn(App $app): float => (new \ReflectionProperty(\Emc\Auth\KennwortController::class, 'mindestSekunden'))
            ->getValue($app->container()->get(\Emc\Auth\KennwortController::class));
        $this->assertSame(3.0, $dauer($this->app($this->sitzung, $mail)));
        $this->assertSame(0.0, $dauer($this->app($this->sitzung)));

        $app = $this->app($this->sitzung);
        $c = $app->container();
        $c->set(\Emc\Auth\KennwortController::class, fn() => new \Emc\Auth\KennwortController(
            $app->view(), $c->get(KennwortVergessen::class), $c->get(\Emc\Auth\Auth::class), $c->get('session'), 0.3
        ));
        $start = microtime(true);
        $r = $app->handle(new Request('POST', '/kennwort/vergessen', [], ['email' => 'niemand@verein.de', '_csrf' => str_repeat(self::CSRF, 64)]));
        $this->assertSame(200, $r->status);
        $this->assertGreaterThanOrEqual(0.29, microtime(true) - $start);
    }

    public function test_neues_kennwort_beendet_andere_sitzungen_und_meldet_an(): void
    {
        $this->mailer = new FakeMailer();
        $id = $this->legeBenutzerAn('max@verein.de');
        $sitzungA = [];
        $this->sitzungAnmelden($sitzungA, $id);
        $this->assertSame(200, $this->app($sitzungA)->handle(new Request('GET', '/fahrzeuge'))->status);

        $this->post('/kennwort/vergessen', ['email' => 'max@verein.de']);
        $token = $this->mailer->letzterToken();

        $form = $this->get('/kennwort/neu', ['t' => $token]);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="neues_passwort"', $form->body);
        $this->assertStringContainsString('name="wiederholung"', $form->body);
        $this->assertStringContainsString('value="' . $token . '"', $form->body);
        $this->assertSame('no-referrer', $form->headers['Referrer-Policy'] ?? null);
        $this->assertStringContainsString('no-store', $form->headers['Cache-Control'] ?? '');

        $anders = $this->post('/kennwort/neu', ['t' => $token, 'neues_passwort' => 'ganz-neues-kennwort', 'wiederholung' => 'anderes-kennwort-x']);
        $this->assertSame(422, $anders->status);
        $this->assertStringContainsString('stimmen nicht überein', $anders->body);
        $kurz = $this->post('/kennwort/neu', ['t' => $token, 'neues_passwort' => 'kurz', 'wiederholung' => 'kurz']);
        $this->assertSame(422, $kurz->status);
        $this->assertStringContainsString('mindestens 10 Zeichen', $kurz->body);

        $r = $this->post('/kennwort/neu', ['t' => $token, 'neues_passwort' => 'ganz-neues-kennwort', 'wiederholung' => 'ganz-neues-kennwort']);
        $this->assertSame(303, $r->status);
        $this->assertSame('/', $r->headers['Location']);
        $this->assertContains('Dein Kennwort wurde geändert. Du bist jetzt angemeldet.', $this->meldungen($this->sitzung));
        $this->assertSame($id, $this->sitzung['benutzer_id']);
        $this->assertSame(200, $this->get('/fahrzeuge')->status);
        $this->assertTrue(password_verify('ganz-neues-kennwort', (string) $this->pdo->query("SELECT passwort_hash FROM benutzer WHERE id = $id")->fetchColumn()));

        // Die alte Sitzung A ist beendet.
        $a = $this->app($sitzungA)->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $a->status);
        $this->assertStringStartsWith('/login', $a->headers['Location']);

        // Der Link funktioniert kein zweites Mal.
        $nochmal = $this->get('/kennwort/neu', ['t' => $token]);
        $this->assertStringContainsString(e(KennwortVergessen::UNGUELTIG), $nochmal->body);
    }

    /** Ein Startpasswort vom Administrator beendet die Sitzungen des Kontos; die Sitzung des Administrators bleibt. */
    public function test_admin_startpasswort_beendet_sitzungen(): void
    {
        $admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
        $max = $this->legeBenutzerAn('max@verein.de');
        $sitzungMax = [];
        $this->sitzungAnmelden($sitzungMax, $max);
        $this->assertSame(200, $this->app($sitzungMax)->handle(new Request('GET', '/fahrzeuge'))->status);
        $this->sitzungAnmelden($this->sitzung, $admin);

        $this->assertSame(303, $this->post('/admin/benutzer/' . $max . '/passwort', ['passwort' => 'start-kennwort-1'])->status);
        $a = $this->app($sitzungMax)->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $a->status);
        $this->assertStringStartsWith('/login', $a->headers['Location']);
        $this->assertSame(200, $this->get('/fahrzeuge')->status);

        // Setzt der Administrator sein eigenes Konto zurück, bleibt er angemeldet (muss dann das Passwort ändern).
        $this->assertSame(303, $this->post('/admin/benutzer/' . $admin . '/passwort', ['passwort' => 'start-kennwort-2'])->status);
        $this->assertSame($admin, $this->sitzung['benutzer_id'] ?? null);
        $r = $this->get('/fahrzeuge');
        $this->assertSame(303, $r->status);
        $this->assertSame('/konto/passwort', $r->headers['Location']);
    }

    /** Eigene Passwortänderung: die ändernde Sitzung bleibt angemeldet, alle anderen enden. */
    public function test_eigene_passwortaenderung_beendet_andere_sitzungen(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $sitzungA = [];
        $this->sitzungAnmelden($sitzungA, $id);
        $this->sitzungAnmelden($this->sitzung, $id);

        $r = $this->post('/konto/passwort', ['altes_passwort' => 'geheim-passwort-1', 'neues_passwort' => 'brandneues-passwort', 'wiederholung' => 'brandneues-passwort']);
        $this->assertSame(303, $r->status);
        $this->assertSame(200, $this->get('/fahrzeuge')->status);
        $a = $this->app($sitzungA)->handle(new Request('GET', '/fahrzeuge'));
        $this->assertSame(303, $a->status);
        $this->assertStringStartsWith('/login', $a->headers['Location']);
    }

    public function test_ungueltiger_token(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $vorher = (string) $this->pdo->query("SELECT passwort_hash FROM benutzer WHERE id = $id")->fetchColumn();
        foreach ([['t' => str_repeat('a', 64)], ['t' => '<script>'], []] as $query) {
            $r = $this->get('/kennwort/neu', $query);
            $this->assertSame(200, $r->status);
            $this->assertStringContainsString(e(KennwortVergessen::UNGUELTIG), $r->body);
            $this->assertStringContainsString('Neuen Link anfordern', $r->body);
            $this->assertStringContainsString('href="/kennwort/vergessen"', $r->body);
            $this->assertStringNotContainsString('name="neues_passwort"', $r->body);
            $this->assertStringNotContainsString('<script>', $r->body);
        }
        $p = $this->post('/kennwort/neu', ['t' => str_repeat('a', 64), 'neues_passwort' => 'ganz-neues-kennwort', 'wiederholung' => 'ganz-neues-kennwort']);
        $this->assertSame(422, $p->status);
        $this->assertStringContainsString(e(KennwortVergessen::UNGUELTIG), $p->body);
        $this->assertStringNotContainsString('name="neues_passwort"', $p->body);
        $this->assertSame($vorher, (string) $this->pdo->query("SELECT passwort_hash FROM benutzer WHERE id = $id")->fetchColumn());
        $this->assertArrayNotHasKey('benutzer_id', $this->sitzung);
    }

    public function test_testumgebung_ohne_postfach_zeigt_link(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $test = $this->post('/kennwort/vergessen', ['email' => 'max@verein.de'], ['testumgebung' => true]);
        $this->assertSame(200, $test->status);
        $this->assertStringContainsString(e(KennwortVergessen::ANTWORT), $test->body);
        $this->assertMatchesRegularExpression('~href="/kennwort/neu\?t=[0-9a-f]{64}"~', $test->body);

        $echt = $this->post('/kennwort/vergessen', ['email' => 'max@verein.de']);
        $this->assertStringContainsString(e(KennwortVergessen::ANTWORT), $echt->body);
        $this->assertStringNotContainsString('/kennwort/neu?t=', $echt->body);
    }

    public function test_admin_sieht_und_erledigt_anfragen(): void
    {
        $admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
        $this->pdo->prepare('INSERT INTO person (vorname, nachname) VALUES (?, ?)')->execute(['Anna', 'Beispiel']);
        $personAnna = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO benutzer (person_id, email, passwort_hash, rolle) VALUES (?, 'anna@verein.de', 'x', 'mitglied')")->execute([$personAnna]);
        $anna = (int) $this->pdo->lastInsertId();
        $bearbeiter = $this->legeBenutzerAn('bea@verein.de', rolle: 'bearbeiter');

        $this->post('/kennwort/vergessen', ['email' => 'anna@verein.de']);
        $this->sitzungAnmelden($this->sitzung, $admin);

        $liste = $this->get('/admin/benutzer');
        foreach ([$liste, $this->get('/personen')] as $seite) {
            $this->assertStringContainsString('1 Anfrage auf neues Kennwort', $seite->body);
            $this->assertStringContainsString('Anfragen ansehen', $seite->body);
        }
        $this->assertStringContainsString('Anna Beispiel', $liste->body);
        $this->assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/', $liste->body);
        $this->assertStringContainsString('/admin/kennwort-anfragen/', $liste->body);

        // Startkennwort setzen erledigt die Anfrage.
        $r = $this->post('/admin/benutzer/' . $anna . '/passwort', ['passwort' => 'start-kennwort-1']);
        $this->assertSame(303, $r->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_anfrage WHERE erledigt_am IS NULL')->fetchColumn());
        $this->assertSame($admin, (int) $this->pdo->query('SELECT erledigt_von_benutzer_id FROM kennwort_anfrage')->fetchColumn());
        $this->assertStringNotContainsString('Anfrage auf neues Kennwort', $this->get('/admin/benutzer')->body);

        // Zwei neue Anfragen, eine davon verwerfen.
        $this->sitzung = ['_csrf' => str_repeat(self::CSRF, 64)];
        $this->pdo->exec('DELETE FROM kennwort_versuch');
        $this->post('/kennwort/vergessen', ['email' => 'anna@verein.de']);
        $this->post('/kennwort/vergessen', ['email' => 'bea@verein.de']);
        // Erst der Datenbankstand (mit Angaben für die Fehlersuche), dann die Anzeige: „Kennwort vergessen“ antwortet
        // immer gleich, eine verlorene Anfrage fiele sonst nur über den fehlenden Hinweis auf.
        $offen = $this->pdo->query('SELECT benutzer_id FROM kennwort_anfrage WHERE erledigt_am IS NULL ORDER BY benutzer_id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([$anna, $bearbeiter], array_map('intval', $offen), 'offene Anfragen; Versuche: '
            . (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_versuch')->fetchColumn()
            . ', Konten: ' . (int) $this->pdo->query('SELECT COUNT(*) FROM benutzer')->fetchColumn());
        $this->sitzungAnmelden($this->sitzung, $admin);
        $this->assertStringContainsString('2 Anfragen auf neues Kennwort', $this->get('/admin/benutzer')->body);
        $anfrage = (int) $this->pdo->query("SELECT id FROM kennwort_anfrage WHERE erledigt_am IS NULL AND benutzer_id = $anna")->fetchColumn();

        // Bearbeiter: kein Hinweis, 403 beim Verwerfen.
        $sitzungB = ['_csrf' => str_repeat(self::CSRF, 64)];
        $this->sitzungAnmelden($sitzungB, $bearbeiter);
        // Nur der Kennwort-Hinweis zählt (Bearbeiter sind Börsenwart und sehen im Menü „Anfragen“ der Börse).
        $this->assertStringNotContainsString('auf neues Kennwort', $this->app($sitzungB)->handle(new Request('GET', '/personen'))->body);
        $verboten = $this->app($sitzungB)->handle(new Request('POST', '/admin/kennwort-anfragen/' . $anfrage . '/verwerfen', [], ['_csrf' => str_repeat(self::CSRF, 64)]));
        $this->assertSame(403, $verboten->status);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_anfrage WHERE erledigt_am IS NULL')->fetchColumn());

        $v = $this->post('/admin/kennwort-anfragen/' . $anfrage . '/verwerfen');
        $this->assertSame(303, $v->status);
        $this->assertSame('/admin/benutzer', $v->headers['Location']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_anfrage WHERE erledigt_am IS NULL')->fetchColumn());
        $this->assertStringContainsString('1 Anfrage auf neues Kennwort', $this->get('/admin/benutzer')->body);
        $this->assertSame(303, $this->post('/admin/kennwort-anfragen/999999/verwerfen')->status);
    }

    public function test_vor_datenbank_update_wartungsseite(): void
    {
        $this->pdo->exec("DELETE FROM schema_migration WHERE version = '010_kennwort_vergessen'");
        try {
            $this->assertSame(503, $this->get('/kennwort/vergessen')->status);
            $this->assertSame(503, $this->post('/kennwort/vergessen', ['email' => 'x@verein.de'])->status);
            $this->assertSame(503, $this->get('/kennwort/neu', ['t' => str_repeat('a', 64)])->status);
        } finally {
            $this->pdo->exec("INSERT INTO schema_migration (version) VALUES ('010_kennwort_vergessen')");
        }
    }
}
