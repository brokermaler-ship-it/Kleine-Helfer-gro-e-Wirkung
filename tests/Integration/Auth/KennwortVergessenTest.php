<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Auth;

use Emc\Auth\KennwortVergessen;
use Emc\Betrieb\Einstellungen;
use Emc\Core\DomainFehler;
use Emc\Mail\Mailer;
use Emc\Protokoll\Aenderungsprotokoll;
use Emc\Tests\Integration\DbTestCase;
use Emc\Tests\Support\FakeMailer;

final class KennwortVergessenTest extends DbTestCase
{
    private const IP = '203.0.113.7';

    private FakeMailer $mailer;

    private string|false $fehlerLog = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Gescheiterter Versand schreibt ins error_log; in Tests nicht auf die Konsole.
        $this->fehlerLog = ini_set('error_log', '/dev/null');
        $this->mailer = new FakeMailer();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->fehlerLog);
    }

    private function dienst(?Mailer $mailer = null, bool $ohneMailer = false, bool $testumgebung = false): KennwortVergessen
    {
        return new KennwortVergessen(
            $this->pdo,
            new Einstellungen($this->pdo),
            new Aenderungsprotokoll($this->pdo),
            $ohneMailer ? null : ($mailer ?? $this->mailer),
            'https://verein.example/emc',
            $testumgebung,
            'EMC 78 Lokschuppen'
        );
    }

    private function zaehle(string $sql, array $werte = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($werte);
        return (int) $st->fetchColumn();
    }

    private function hash(int $benutzerId): string
    {
        return (string) $this->pdo->query("SELECT passwort_hash FROM benutzer WHERE id = $benutzerId")->fetchColumn();
    }

    public function test_token_nur_als_hash_60_minuten_gueltig(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->assertNull($this->dienst()->beantrage(' MAX@Verein.de ', self::IP));

        $this->assertCount(1, $this->mailer->gesendet);
        $n = $this->mailer->gesendet[0];
        $this->assertSame('max@verein.de', $n->an);
        $this->assertSame('Neues Kennwort für EMC 78 Lokschuppen', $n->betreff);
        $this->assertStringContainsString('Max', $n->text);
        $this->assertStringContainsString('https://verein.example/emc/kennwort/neu?t=', $n->text);
        $this->assertStringContainsString('Der Link ist 60 Minuten gültig und funktioniert nur einmal. Wenn du kein neues Kennwort angefordert hast, kannst du diese E-Mail ignorieren.', $n->text);
        $token = $this->mailer->letzterToken();
        $this->assertNotNull($token);

        $zeile = $this->pdo->query('SELECT *, TIMESTAMPDIFF(SECOND, NOW(), gueltig_bis) AS rest FROM kennwort_token')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $zeile);
        $this->assertSame($id, (int) $zeile[0]['benutzer_id']);
        $this->assertSame(hash('sha256', $token), $zeile[0]['token_hash']);
        $this->assertNotSame($token, $zeile[0]['token_hash']);
        $this->assertEqualsWithDelta(3600, (int) $zeile[0]['rest'], 60);
        $this->assertNull($zeile[0]['verwendet_am']);
        $this->assertSame($id, $this->dienst()->pruefeToken($token));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage'));
    }

    public function test_neue_anfrage_macht_alten_token_ungueltig(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $alt = $this->mailer->letzterToken();
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $neu = $this->mailer->letzterToken();

        $this->assertNotSame($alt, $neu);
        $this->assertNull($this->dienst()->pruefeToken($alt));
        $this->assertSame($id, $this->dienst()->pruefeToken($neu));
        try {
            $this->dienst()->setzeNeu($alt, 'ganz-neues-kennwort');
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame(KennwortVergessen::UNGUELTIG, $e->getMessage());
        }
    }

    public function test_token_einmal_verwendbar(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $token = $this->mailer->letzterToken();

        $this->assertSame($id, $this->dienst()->setzeNeu($token, 'ganz-neues-kennwort'));
        $this->assertTrue(password_verify('ganz-neues-kennwort', $this->hash($id)));
        $this->assertNotNull($this->pdo->query('SELECT verwendet_am FROM kennwort_token')->fetchColumn());

        $this->assertNull($this->dienst()->pruefeToken($token));
        $this->expectExceptionMessage(KennwortVergessen::UNGUELTIG);
        $this->dienst()->setzeNeu($token, 'noch-ein-kennwort');
    }

    public function test_abgelaufener_token(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $vorher = $this->hash($id);
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $token = $this->mailer->letzterToken();
        $this->pdo->exec('UPDATE kennwort_token SET gueltig_bis = NOW() - INTERVAL 1 MINUTE');

        $this->assertNull($this->dienst()->pruefeToken($token));
        try {
            $this->dienst()->setzeNeu($token, 'ganz-neues-kennwort');
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertSame(KennwortVergessen::UNGUELTIG, $e->getMessage());
        }
        $this->assertSame($vorher, $this->hash($id));
    }

    public function test_token_deaktiviertes_konto(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $token = $this->mailer->letzterToken();
        $this->pdo->exec("UPDATE benutzer SET aktiv = 0 WHERE id = $id");

        $this->assertNull($this->dienst()->pruefeToken($token));
        $this->expectExceptionMessage(KennwortVergessen::UNGUELTIG);
        $this->dienst()->setzeNeu($token, 'ganz-neues-kennwort');
    }

    public function test_manipulierter_token(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $token = $this->mailer->letzterToken();
        $hash = (string) $this->pdo->query('SELECT token_hash FROM kennwort_token')->fetchColumn();

        foreach ([substr($token, 0, 63) . ($token[63] === 'a' ? 'b' : 'a'), strtoupper($token), $hash, '', 'x', $token . 'a', "$token\n"] as $falsch) {
            $this->assertNull($this->dienst()->pruefeToken($falsch), json_encode($falsch));
        }
    }

    public function test_setze_neu_prueft_kennwortregel(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $vorher = $this->hash($id);
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $token = $this->mailer->letzterToken();

        try {
            $this->dienst()->setzeNeu($token, '123456789');
            $this->fail('DomainFehler erwartet');
        } catch (DomainFehler $e) {
            $this->assertStringContainsString('mindestens 10 Zeichen', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame($vorher, $this->hash($id));
        $this->assertSame($id, $this->dienst()->pruefeToken($token));
    }

    public function test_setze_neu_erhoeht_kennwort_stand_und_erledigt_anfragen(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de', mussAendern: true);
        $this->dienst(ohneMailer: true)->beantrage('max@verein.de', self::IP);
        $this->assertSame(1, $this->dienst()->anzahlOffen());
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $token = $this->mailer->letzterToken();

        $this->dienst()->setzeNeu($token, 'ganz-neues-kennwort');

        $z = $this->pdo->query("SELECT kennwort_stand, muss_passwort_aendern FROM benutzer WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $z['kennwort_stand']);
        $this->assertSame(0, (int) $z['muss_passwort_aendern']);
        $this->assertSame(0, $this->dienst()->anzahlOffen());
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage WHERE erledigt_am IS NOT NULL AND erledigt_von_benutzer_id IS NULL'));
        $eintrag = $this->pdo->query("SELECT benutzer_id, aenderungen FROM aenderungsprotokoll WHERE objekt_typ = 'benutzer' AND objekt_id = $id ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($id, (int) $eintrag['benutzer_id']);
        $this->assertSame(['passwort' => ['alt' => null, 'neu' => 'über „Kennwort vergessen“ neu gesetzt']], json_decode($eintrag['aenderungen'], true));
        $this->assertStringNotContainsString('ganz-neues-kennwort', (string) $this->pdo->query('SELECT GROUP_CONCAT(aenderungen) FROM aenderungsprotokoll')->fetchColumn());
    }

    public function test_begrenzung_je_email_und_ip(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        for ($i = 0; $i < KennwortVergessen::MAX_JE_EMAIL + 1; $i++) {
            $this->dienst()->beantrage('max@verein.de', '198.51.100.' . $i);
        }
        $this->assertCount(3, $this->mailer->gesendet);
        // Überschrittene Versuche werden nicht gezählt.
        $this->assertSame(3, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch'));

        // Eine IP mit vielen verschiedenen Adressen: der 11. Versuch bleibt ohne Wirkung.
        $this->pdo->exec('DELETE FROM kennwort_versuch');
        $this->mailer->gesendet = [];
        for ($i = 0; $i < KennwortVergessen::MAX_JE_IP; $i++) {
            $this->dienst()->beantrage('fremd' . $i . '@verein.de', self::IP);
        }
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $this->assertSame([], $this->mailer->gesendet);
        $this->dienst()->beantrage('max@verein.de', '192.0.2.1');
        $this->assertCount(1, $this->mailer->gesendet);

        // Weder Klartext-IP noch Klartext-Adresse.
        $alles = json_encode($this->pdo->query('SELECT * FROM kennwort_versuch')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString(self::IP, $alles);
        $this->assertStringNotContainsString('verein.de', $alles);
        $this->assertStringNotContainsString(hash('sha256', self::IP), $alles);
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch WHERE email_hash = ?', [hash('sha256', 'max@verein.de')]));
        $this->assertSame(64, strlen((string) (new Einstellungen($this->pdo))->hole('kennwort_ip_salz')));

        // Einträge älter als 24 Stunden verschwinden beim nächsten Aufruf.
        $this->pdo->exec('UPDATE kennwort_versuch SET zeitpunkt = NOW() - INTERVAL 25 HOUR');
        $this->dienst()->beantrage('jemand@verein.de', '192.0.2.99');
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch'));
    }

    /** Die Datenbank vergleicht Adressen ohne Groß-/Kleinschreibung und Akzente: die Begrenzung zählt je Konto. */
    public function test_begrenzung_gilt_fuer_schreibvarianten(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        foreach (['max@verein.de', 'MAX@Verein.DE', 'máx@verein.de ', 'Mäx@verein.de', 'mâx@VEREIN.de'] as $i => $variante) {
            $this->dienst()->beantrage($variante, '198.51.100.' . $i);
        }
        $this->assertCount(KennwortVergessen::MAX_JE_EMAIL, $this->mailer->gesendet);
        $this->assertSame(3, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch WHERE email_hash = ?', [hash('sha256', 'max@verein.de')]));
        $this->assertSame(3, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch'));
        // Der zuletzt verschickte Link bleibt gültig.
        $this->assertNotNull($this->dienst()->pruefeToken((string) $this->mailer->letzterToken()));
    }

    /** beantrage sperrt das Konto: parallele Anfragen laufen nacheinander und hinterlassen nur einen gültigen Token. */
    public function test_beantrage_sperrt_das_konto(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $andere = self::verbindung();
        $andere->beginTransaction();
        $andere->query("SELECT id FROM benutzer WHERE id = $id FOR UPDATE")->fetchAll();
        try {
            $this->dienst()->beantrage('max@verein.de', self::IP);
            $this->fail('Sperrwartezeit erwartet');
        } catch (\PDOException) {
            $this->assertFalse($this->pdo->inTransaction());
        } finally {
            $andere->rollBack();
        }
        $this->assertSame([], $this->mailer->gesendet);
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_token'));
        // Alles in einer Transaktion: auch der Versuch ist nicht gezählt.
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_versuch'));
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_token WHERE verwendet_am IS NULL'));
    }

    public function test_anfragen_deaktivierter_konten_nicht_offen(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->dienst(ohneMailer: true)->beantrage('max@verein.de', self::IP);
        $this->assertSame(1, $this->dienst()->anzahlOffen());
        $this->pdo->exec("UPDATE benutzer SET aktiv = 0 WHERE id = $id");
        $this->assertSame(0, $this->dienst()->anzahlOffen());
        $this->assertSame([], $this->dienst()->offeneAnfragen());
    }

    public function test_versand_scheitert_faellt_auf_anfrage_zurueck(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $mailer = new FakeMailer(scheitert: true);
        $this->assertNull($this->dienst($mailer, testumgebung: true)->beantrage('max@verein.de', self::IP));

        $this->assertSame(1, $mailer->versuche);
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_token'));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage WHERE benutzer_id = ? AND erledigt_am IS NULL', [$id]));
        // Eine weitere Anfrage legt keine zweite offene an.
        $this->pdo->exec('UPDATE kennwort_anfrage SET erstellt_am = NOW() - INTERVAL 2 HOUR');
        $this->dienst($mailer)->beantrage('max@verein.de', self::IP);
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage'));
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage WHERE erstellt_am > NOW() - INTERVAL 5 MINUTE'));
    }

    public function test_ohne_mailer_anfrage_und_nur_testumgebung_liefert_link(): void
    {
        $id = $this->legeBenutzerAn('max@verein.de');
        $this->assertNull($this->dienst(ohneMailer: true)->beantrage('max@verein.de', self::IP));
        $this->assertSame(1, $this->dienst()->anzahlOffen());
        $anfragen = $this->dienst()->offeneAnfragen();
        $this->assertCount(1, $anfragen);
        $this->assertSame($id, $anfragen[0]['benutzer_id']);
        $this->assertSame('max@verein.de', $anfragen[0]['email']);
        $this->assertSame('Max Muster', $anfragen[0]['name']);
        $this->assertNotSame('', $anfragen[0]['erstellt_am']);

        $link = $this->dienst(ohneMailer: true, testumgebung: true)->beantrage('max@verein.de', self::IP);
        $this->assertNotNull($link);
        $this->assertStringStartsWith('https://verein.example/emc/kennwort/neu?t=', $link);
        $this->assertSame($id, $this->dienst()->pruefeToken(substr($link, strlen('https://verein.example/emc/kennwort/neu?t='))));
        $this->assertSame(1, $this->dienst()->anzahlOffen());
        // Unbekannte Adresse: auch in der Testumgebung kein Link.
        $this->assertNull($this->dienst(ohneMailer: true, testumgebung: true)->beantrage('niemand@verein.de', self::IP));
        // Mit Mailer: nie ein Link, auch nicht in der Testumgebung.
        $this->assertNull($this->dienst(testumgebung: true)->beantrage('max@verein.de', self::IP));
    }

    public function test_unbekannte_adresse_keine_anfrage_aber_protokoll_ohne_adresse(): void
    {
        $this->legeBenutzerAn('max@verein.de');
        $this->dienst(ohneMailer: true)->beantrage('unbekannt@woanders.de', self::IP);

        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_token'));
        $this->assertSame([], $this->mailer->gesendet);
        $eintraege = $this->pdo->query("SELECT * FROM aenderungsprotokoll WHERE objekt_typ = 'kennwort_anfrage'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $eintraege);
        $this->assertNull($eintraege[0]['benutzer_id']);
        $this->assertSame(0, (int) $eintraege[0]['objekt_id']);
        $this->assertStringNotContainsString('woanders', (string) $eintraege[0]['aenderungen']);
        $this->assertStringNotContainsString('unbekannt@', (string) $eintraege[0]['aenderungen']);
    }

    public function test_deaktiviertes_konto_bekommt_weder_mail_noch_anfrage(): void
    {
        $this->legeBenutzerAn('max@verein.de', aktiv: false);
        $this->dienst()->beantrage('max@verein.de', self::IP);
        $this->dienst(ohneMailer: true)->beantrage('max@verein.de', self::IP);
        $this->assertSame([], $this->mailer->gesendet);
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage'));
        $this->assertSame(0, $this->zaehle('SELECT COUNT(*) FROM kennwort_token'));
    }

    public function test_erledigen_und_verwerfen(): void
    {
        $admin = $this->legeBenutzerAn('admin@verein.de', rolle: 'admin');
        $a = $this->legeBenutzerAn('anna@verein.de');
        $b = $this->legeBenutzerAn('bernd@verein.de');
        $this->dienst(ohneMailer: true)->beantrage('anna@verein.de', self::IP);
        $this->dienst(ohneMailer: true)->beantrage('bernd@verein.de', self::IP);
        $this->assertSame(2, $this->dienst()->anzahlOffen());

        $this->dienst()->erledigeFuer($a, $admin);
        $this->assertSame(1, $this->zaehle('SELECT COUNT(*) FROM kennwort_anfrage WHERE benutzer_id = ? AND erledigt_von_benutzer_id = ?', [$a, $admin]));
        $offen = $this->dienst()->offeneAnfragen();
        $this->assertSame([$b], array_column($offen, 'benutzer_id'));

        $this->assertTrue($this->dienst()->verwerfe($offen[0]['id'], $admin));
        $this->assertFalse($this->dienst()->verwerfe($offen[0]['id'], $admin));
        $this->assertSame(0, $this->dienst()->anzahlOffen());
    }

    public function test_dienst_im_container(): void
    {
        $app = \Emc\Core\App::erzeuge(self::testConfig());
        $this->assertInstanceOf(KennwortVergessen::class, $app->container()->get(KennwortVergessen::class));
        $this->assertNull($app->container()->get(Mailer::class));
        $app = \Emc\Core\App::erzeuge(['mail_smtp_host' => 'mail.example.org', 'mail_absender' => 'verein@example.org', 'oeffentliche_url' => 'https://verein.example'] + self::testConfig());
        $this->assertInstanceOf(\Emc\Mail\SmtpMailer::class, $app->container()->get(Mailer::class));
    }
}
