<?php

declare(strict_types=1);

namespace Emc\Tests\Integration\Wartung;

use Emc\Core\App;
use Emc\Core\Request;
use Emc\Core\Response;
use Emc\Core\Session;
use Emc\Tests\Integration\DbTestCase;
use Emc\Wartung\WartungRepository;

final class WartungUebersichtHttpTest extends DbTestCase
{
    private array $sitzung = [];
    private int $spurweite;
    private int $lok;
    private int $anlage;
    private int $abgestellt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spurweite = (int) $this->pdo->query("SELECT id FROM spurweite WHERE name = 'H0'")->fetchColumn();
        $id = $this->legeBenutzerAn('mitglied@verein.de', 'geheim-passwort-1', 'mitglied');
        $this->sitzung = [];
        $this->sitzungAnmelden($this->sitzung, $id);
        $this->sitzung['_csrf'] = str_repeat('a', 64);

        $this->lok = $this->fahrzeug('EMC-0001', 'Überfällige Lok', 'einsatzbereit');
        $this->abgestellt = $this->fahrzeug('EMC-0002', 'Abgestellte Lok', 'ausgemustert');
        $this->pdo->prepare("INSERT INTO anlage (nummer, name, spurweite_id) VALUES (1, 'Testanlage', ?)")->execute([$this->spurweite]);
        $this->anlage = (int) $this->pdo->lastInsertId();
        $heute = new \DateTimeImmutable('today');
        $this->wartung('fahrzeug', $this->lok, $heute->modify('-13 months'), 'Kupplungen prüfen');
        $this->wartung('fahrzeug', $this->abgestellt, $heute->modify('-30 months'), 'Kupplungen prüfen');
        $this->wartung('anlage', $this->anlage, $heute->modify('-75 days'), 'Gleise reinigen');
    }

    private function fahrzeug(string $nummer, string $bezeichnung, string $status): int
    {
        $this->pdo->prepare("INSERT INTO fahrzeug (inventarnummer, bezeichnung, gattung, spurweite_id, status) VALUES (?, ?, 'lok', ?, ?)")
            ->execute([$nummer, $bezeichnung, $this->spurweite, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function wartung(string $typ, int $id, \DateTimeImmutable $datum, string $art): void
    {
        $st = $this->pdo->prepare('SELECT id FROM wartungsart WHERE name = ?');
        $st->execute([$art]);
        (new WartungRepository($this->pdo))->eintragen($typ, $id, [
            'datum' => $datum->format('Y-m-d'), 'betriebsstunden' => null, 'stundenstand_bezug' => null, 'ausgefuehrt_von_person_id' => null, 'notiz' => null,
        ], [(int) $st->fetchColumn()], null);
    }

    private function get(string $pfad, array $query = []): Response
    {
        return App::erzeuge(self::testConfig(), new Session($this->sitzung))->handle(new Request('GET', $pfad, $query));
    }

    /** Die Tabellenzeile, in der $text vorkommt. */
    private static function zeileMit(string $html, string $text): string
    {
        preg_match_all('#<tr\b.*?</tr>#s', $html, $treffer);
        foreach ($treffer[0] as $zeile) {
            if (str_contains($zeile, $text)) {
                return $zeile;
            }
        }
        self::fail('Keine Zeile mit „' . $text . '“');
    }

    public function test_uebersicht_sortiert_und_ohne_ausgemusterte(): void
    {
        $r = $this->get('/wartung');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Überfällige Lok', $r->body);
        self::assertStringContainsString('Testanlage', $r->body);
        self::assertLessThan(strpos($r->body, 'Testanlage'), strpos($r->body, 'Überfällige Lok'));
        self::assertStringNotContainsString('Abgestellte Lok', $r->body);
        $zeile = self::zeileMit($r->body, 'Kupplungen prüfen');
        self::assertStringContainsString('überfällig', $zeile);
        self::assertStringContainsString('EMC-0001', $zeile);
        self::assertStringContainsString('href="/fahrzeuge/' . $this->lok . '"', $zeile);
        $zeile = self::zeileMit($r->body, 'Gleise reinigen');
        self::assertStringContainsString('bald fällig', $zeile);
        self::assertStringContainsString('A1', $zeile);
        self::assertStringContainsString('href="/anlagen/' . $this->anlage . '"', $zeile);
        self::assertStringContainsString('noch nie erfasst', $r->body);
    }

    public function test_uebersicht_filter(): void
    {
        $r = $this->get('/wartung', ['typ' => 'anlage']);
        self::assertSame(200, $r->status);
        self::assertStringNotContainsString('Überfällige Lok', $r->body);
        self::assertStringContainsString('Testanlage', $r->body);
        self::assertMatchesRegularExpression('#<option value="anlage" selected>#', $r->body);

        $r = $this->get('/wartung', ['eigentuemer' => 'mitglied']);
        self::assertStringNotContainsString('Überfällige Lok', $r->body);
        self::assertStringNotContainsString('Testanlage', $r->body);
        self::assertStringContainsString('Keine Fälligkeiten', $r->body);
    }

    public function test_uebersicht_zaehlt_und_filtert_nach_stand(): void
    {
        $r = $this->get('/wartung');
        self::assertStringContainsString('1 überfällig', $r->body);
        self::assertStringContainsString('1 bald fällig', $r->body);
        self::assertStringContainsString('6 noch nie erfasst', $r->body);
        self::assertStringContainsString('0 in Ordnung', $r->body);

        $r = $this->get('/wartung', ['stand' => 'nie']);
        self::assertSame(200, $r->status);
        self::assertMatchesRegularExpression('#<option value="nie" selected>#', $r->body);
        self::assertStringNotContainsString('Kupplungen prüfen', $r->body);
        self::assertStringNotContainsString('Gleise reinigen', $r->body);
        self::assertStringContainsString('Weichen und Antriebe prüfen', $r->body);
        self::assertSame(6, substr_count($r->body, '>noch nie erfasst</span>'));
        // Die Zählung bleibt die ohne Stand-Filter.
        self::assertStringContainsString('1 überfällig', $r->body);

        $r = $this->get('/wartung', ['stand' => 'rot']);
        self::assertStringContainsString('Kupplungen prüfen', $r->body);
        self::assertStringNotContainsString('Weichen und Antriebe prüfen', $r->body);
        $r = $this->get('/wartung', ['stand' => 'quatsch']);
        self::assertStringContainsString('Weichen und Antriebe prüfen', $r->body);
    }

    public function test_alle_arten_abgeschaltet(): void
    {
        $this->pdo->prepare("INSERT INTO wartungsplan_ausnahme (objekt_typ, objekt_id, wartungsart_id, aus) SELECT 'anlage', ?, id, 1 FROM wartungsart WHERE gilt_fuer = 'anlage'")
            ->execute([$this->anlage]);
        $r = $this->get('/anlagen/' . $this->anlage);
        self::assertStringContainsString('Alle Wartungsarten sind für dieses Objekt abgeschaltet.', $r->body);
        self::assertStringNotContainsString('Für dieses Objekt sind keine Wartungsarten vorgesehen.', $r->body);
    }

    public function test_startseite_mit_kachel(): void
    {
        $r = $this->get('/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Wartung', $r->body);
        self::assertStringContainsString('1 überfällig', $r->body);
        // „bald fällig“ und „noch nie erfasst“ getrennt gezählt.
        self::assertStringContainsString('1 bald fällig', $r->body);
        self::assertStringContainsString('6 noch nie erfasst', $r->body);
        self::assertStringContainsString('Alle Fälligkeiten', $r->body);
        self::assertStringContainsString('href="/fahrzeuge/' . $this->lok . '"', $r->body);
        self::assertStringContainsString('href="/fahrzeuge"', $r->body);
        self::assertStringContainsString('href="/anlagen"', $r->body);
        self::assertStringNotContainsString('Abgestellte Lok', $r->body);
    }

    public function test_detailseiten_zeigen_ampel(): void
    {
        $r = $this->get('/fahrzeuge/' . $this->lok);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('überfällig', self::zeileMit($r->body, 'Kupplungen prüfen'));
        self::assertStringContainsString('noch nie erfasst', self::zeileMit($r->body, 'Getriebe und Lager schmieren'));
        self::assertStringContainsString('12 Monate', self::zeileMit($r->body, 'Kupplungen prüfen'));

        $r = $this->get('/anlagen/' . $this->anlage);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('noch nie erfasst', self::zeileMit($r->body, 'Weichen und Antriebe prüfen'));
        self::assertStringContainsString('bald fällig', self::zeileMit($r->body, 'Gleise reinigen'));

        $r = $this->get('/fahrzeuge/' . $this->abgestellt);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Ausgemusterte Fahrzeuge werden im Wartungsplan nicht berücksichtigt.', $r->body);
    }

    public function test_eigenes_intervall_gekennzeichnet(): void
    {
        $st = $this->pdo->prepare("INSERT INTO wartungsplan_ausnahme (objekt_typ, objekt_id, wartungsart_id, aus, intervall_monate) SELECT 'fahrzeug', ?, id, 0, 24 FROM wartungsart WHERE name = 'Kupplungen prüfen'");
        $st->execute([$this->lok]);
        $zeile = self::zeileMit($this->get('/fahrzeuge/' . $this->lok)->body, 'Kupplungen prüfen');
        self::assertStringContainsString('eigenes Intervall', $zeile);
        self::assertStringContainsString('24 Monate', $zeile);
        self::assertStringContainsString('in Ordnung', $zeile);
    }

    public function test_menue_enthaelt_wartung(): void
    {
        $r = $this->get('/fahrzeuge');
        self::assertMatchesRegularExpression('#<a class="nav-link" href="/wartung">Wartung</a>#', $r->body);
    }
}
