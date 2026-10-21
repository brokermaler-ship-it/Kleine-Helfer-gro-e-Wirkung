<?php

declare(strict_types=1);

namespace Emc\Auth;

use Emc\Betrieb\Einstellungen;
use Emc\Core\DomainFehler;
use Emc\Mail\Mailer;
use Emc\Mail\Nachricht;
use Emc\Personen\BenutzerRepository;
use Emc\Protokoll\Aenderungsprotokoll;

/**
 * „Kennwort vergessen“: Link per E-Mail (Token nur als SHA-256-Hash, 60 Minuten, einmal verwendbar) oder – ohne
 * Postfach bzw. wenn der Versand scheitert – eine Anfrage an die Administratoren. Nach außen ist das Ergebnis
 * immer gleich; nur bei einem bekannten aktiven Konto entsteht ein Token oder eine Anfrage.
 */
final class KennwortVergessen
{
    public const TOKEN_MINUTEN = 60;
    public const MAX_JE_EMAIL = 3;
    public const MAX_JE_IP = 10;
    public const AUFBEWAHRUNG_STUNDEN = 24;
    public const ANTWORT = 'Wenn die Adresse bei uns bekannt ist, bekommst du gleich eine E-Mail mit einem Link zum Zurücksetzen. Kommt keine E-Mail an, meldet sich ein Administrator mit einem neuen Kennwort bei dir.';
    public const UNGUELTIG = 'Der Link ist ungültig oder abgelaufen.';
    /** Einstellung mit dem geheimen Salz für IP- und E-Mail-Hashes in `kennwort_versuch`. */
    public const SALZ = 'kennwort_ip_salz';

    public function __construct(
        private \PDO $pdo,
        private Einstellungen $einstellungen,
        private Aenderungsprotokoll $protokoll,
        private ?Mailer $mailer,
        private string $linkBasis,
        private bool $testumgebung,
        private string $appName,
    ) {
        $this->linkBasis = rtrim($linkBasis, '/');
    }

    /**
     * Hash, unter dem eine E-Mail-Adresse in `kennwort_versuch` steht (auch für die DSGVO-Löschung): HMAC mit dem
     * geheimen Salz der Instanz, damit sich die Adressen nicht per Wörterbuch der Mitgliederadressen zurückrechnen lassen.
     */
    public static function emailHash(string $email, string $salz): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $salz);
    }

    /**
     * Nimmt eine Anfrage entgegen. Rückgabe: der Link nur in der Testumgebung ohne Mailer und nur bei bekanntem
     * aktivem Konto, sonst immer null – Aufrufer zeigen unabhängig davon dieselbe Antwort (ANTWORT).
     */
    public function beantrage(string $email, string $ip): ?string
    {
        $email = mb_strtolower(trim($email));
        $salz = $this->salz();
        $ipHash = hash_hmac('sha256', $ip, $salz);

        $this->pdo->exec('DELETE FROM kennwort_versuch WHERE zeitpunkt < NOW() - INTERVAL ' . self::AUFBEWAHRUNG_STUNDEN . ' HOUR');
        $this->pdo->exec('DELETE FROM kennwort_token WHERE gueltig_bis < NOW() - INTERVAL ' . self::AUFBEWAHRUNG_STUNDEN . ' HOUR');

        $konto = null;
        if ($email !== '' && mb_strlen($email) <= 190) {
            $st = $this->pdo->prepare(
                'SELECT b.id, b.email, p.vorname, p.nachname FROM benutzer b JOIN person p ON p.id = b.person_id WHERE b.email = ? AND b.aktiv = 1'
            );
            $st->execute([$email]);
            $konto = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        // Begrenzung je Konto: die Datenbank vergleicht ohne Groß-/Kleinschreibung und Akzente („máx@…“ trifft
        // „max@…“), deshalb zählt bei einem Treffer die gespeicherte Adresse, nicht die Schreibweise der Eingabe.
        $emailHash = self::emailHash($konto !== null ? (string) $konto['email'] : $email, $salz);

        // Token in beiden Fällen erzeugen, damit bekannte und unbekannte Adressen vergleichbar viel Arbeit machen.
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $link = $this->linkBasis . '/kennwort/neu?t=' . $token;

        // Begrenzung, Zählung und Token in einer Transaktion mit gesperrtem Konto: parallele Anfragen laufen
        // nacheinander, überschreiten die Begrenzung nicht und hinterlassen höchstens einen gültigen Token.
        // READ COMMITTED: keine Lückensperren bei DELETE ohne Treffer + INSERT, sonst geraten parallele Anfragen
        // verschiedener Konten in einen Deadlock. Die Sperre der Kontozeile serialisiert je Konto weiterhin.
        $this->beginneTransaktion();
        try {
            $sperre = $this->pdo->prepare('SELECT id FROM benutzer WHERE id = ? AND aktiv = 1 FOR UPDATE');
            $sperre->execute([$konto !== null ? (int) $konto['id'] : 0]);
            if ($sperre->fetchColumn() === false) {
                $konto = null; // unbekannt oder inzwischen deaktiviert
            }
            if ($this->anzahlVersuche('email_hash', $emailHash) >= self::MAX_JE_EMAIL
                || $this->anzahlVersuche('ip_hash', $ipHash) >= self::MAX_JE_IP
            ) {
                $this->pdo->commit();
                return null; // still nichts tun und nicht zählen
            }
            $this->pdo->prepare('INSERT INTO kennwort_versuch (email_hash, ip_hash, zeitpunkt) VALUES (?, ?, NOW())')
                ->execute([$emailHash, $ipHash]);

            if ($konto === null) {
                // Gleichartige Abfragen wie bei einem bekannten Konto (Tokens aufräumen, offene Anfrage suchen), ändern nichts.
                $this->pdo->prepare('DELETE FROM kennwort_token WHERE token_hash = ? AND verwendet_am IS NULL')->execute([$tokenHash]);
                $this->pdo->prepare('SELECT id FROM kennwort_anfrage WHERE benutzer_id = 0 AND erledigt_am IS NULL LIMIT 1')->execute();
                // Nur gezählt, ohne Adresse: niemand soll Fremdadressen in die Verwaltung einschleusen können.
                $this->protokoll->schreibe(null, 'kennwort_anfrage', 0, 'angelegt', ['adresse' => ['alt' => null, 'neu' => 'unbekannt']]);
                $this->pdo->commit();
                return null;
            }

            $id = (int) $konto['id'];
            $this->pdo->prepare('DELETE FROM kennwort_token WHERE benutzer_id = ? AND verwendet_am IS NULL')->execute([$id]);
            // Ohne Postfach braucht nur die Testinstanz einen Token (sie zeigt den Link an).
            if ($this->mailer !== null || $this->testumgebung) {
                $this->pdo->prepare(
                    'INSERT INTO kennwort_token (benutzer_id, token_hash, gueltig_bis) VALUES (?, ?, NOW() + INTERVAL ' . self::TOKEN_MINUTEN . ' MINUTE)'
                )->execute([$id, $tokenHash]);
            }
            $this->pdo->commit();
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($t instanceof \PDOException && self::istSperrkonflikt($t)) {
                // Deadlock oder Sperrwartezeit: nichts gespeichert, dieselbe neutrale Antwort wie immer (kein Fehler 500).
                error_log('Kennwort vergessen: Sperrkonflikt, Anfrage verworfen.');
                return null;
            }
            throw $t;
        }

        // Versand erst nach dem Commit (die Sperre hält nicht während der SMTP-Verbindung).
        if ($this->mailer !== null) {
            $gesendet = false;
            try {
                $this->mailer->sende($this->nachricht($konto, $link));
                $gesendet = true;
            } catch (\Throwable $t) {
                // Rückfall auf die Admin-Anfrage. Nie Adresse, Token oder Zugangsdaten ins Fehlerprotokoll.
                $this->pdo->prepare('DELETE FROM kennwort_token WHERE token_hash = ?')->execute([$tokenHash]);
                error_log('Kennwort vergessen: E-Mail-Versand gescheitert (' . $t::class . ': ' . ($t instanceof \Emc\Mail\MailFehler ? $t->getMessage() : 'unerwarteter Fehler') . '), Anfrage an die Administratoren angelegt.');
            }
            if ($gesendet) {
                $this->protokoll->schreibe(null, 'benutzer', $id, 'geaendert', ['passwort' => ['alt' => null, 'neu' => 'Link „Kennwort vergessen“ per E-Mail verschickt']]);
                return null;
            }
        }

        if (!$this->legeAnfrageAn($id)) {
            return null;
        }
        $this->protokoll->schreibe(null, 'benutzer', $id, 'geaendert', ['passwort' => ['alt' => null, 'neu' => 'neues Kennwort bei den Administratoren angefragt']]);
        return $this->mailer === null && $this->testumgebung ? $link : null;
    }

    /** Benutzer-ID, wenn der Token existiert, unbenutzt und nicht abgelaufen ist und das Konto aktiv ist. */
    public function pruefeToken(string $token): ?int
    {
        $zeile = $this->tokenZeile($token, false);
        return $zeile === null ? null : (int) $zeile['benutzer_id'];
    }

    /**
     * Setzt das neue Kennwort, entwertet alle Tokens des Kontos, erledigt offene Anfragen und erhöht den
     * Kennwort-Stand (beendet alle anderen Sitzungen).
     *
     * @throws DomainFehler UNGUELTIG oder die Meldung der Kennwortregel (Token bleibt dann gültig)
     */
    public function setzeNeu(string $token, #[\SensitiveParameter] string $kennwort): int
    {
        $this->beginneTransaktion();
        try {
            // Gleiche Sperrreihenfolge wie beantrage: Konto zuerst (benutzer_id ungesperrt gelesen), dann der Token.
            $konto = $this->tokenZeile($token, false);
            if ($konto !== null) {
                $this->pdo->prepare('SELECT id FROM benutzer WHERE id = ? FOR UPDATE')->execute([(int) $konto['benutzer_id']]);
            }
            $zeile = $konto === null ? null : $this->tokenZeile($token, true);
            if ($zeile === null || (int) $zeile['benutzer_id'] !== (int) $konto['benutzer_id']) {
                throw new DomainFehler(self::UNGUELTIG);
            }
            $regel = BenutzerRepository::pruefePasswort($kennwort);
            if ($regel !== null) {
                throw new DomainFehler($regel);
            }
            $id = (int) $zeile['benutzer_id'];
            $this->pdo->prepare('UPDATE kennwort_token SET verwendet_am = NOW() WHERE id = ?')->execute([(int) $zeile['id']]);
            $this->pdo->prepare('DELETE FROM kennwort_token WHERE benutzer_id = ? AND id <> ?')->execute([$id, (int) $zeile['id']]);
            // Erhöht auch kennwort_stand und beendet damit alle anderen Sitzungen.
            (new BenutzerRepository($this->pdo))->passwortSetzen($id, $kennwort, false);
            $this->pdo->prepare('UPDATE kennwort_anfrage SET erledigt_am = NOW() WHERE benutzer_id = ? AND erledigt_am IS NULL')->execute([$id]);
            $this->protokoll->schreibe($id, 'benutzer', $id, 'geaendert', [
                'passwort' => ['alt' => null, 'neu' => 'über „Kennwort vergessen“ neu gesetzt'],
            ]);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }
    }

    /** @return list<array{id:int, benutzer_id:int, email:string, name:string, erstellt_am:string}> nur aktive Konten, älteste zuerst */
    public function offeneAnfragen(): array
    {
        $zeilen = $this->pdo->query(
            'SELECT a.id, a.benutzer_id, b.email, p.vorname, p.nachname, a.erstellt_am FROM kennwort_anfrage a '
            . 'JOIN benutzer b ON b.id = a.benutzer_id JOIN person p ON p.id = b.person_id '
            . 'WHERE a.erledigt_am IS NULL AND b.aktiv = 1 ORDER BY a.erstellt_am, a.id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn(array $z): array => [
            'id' => (int) $z['id'],
            'benutzer_id' => (int) $z['benutzer_id'],
            'email' => (string) $z['email'],
            'name' => trim($z['vorname'] . ' ' . $z['nachname']),
            'erstellt_am' => (string) $z['erstellt_am'],
        ], $zeilen);
    }

    public function anzahlOffen(): int
    {
        // Nur aktive Konten: ein inzwischen deaktiviertes Konto braucht kein neues Kennwort.
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM kennwort_anfrage a JOIN benutzer b ON b.id = a.benutzer_id WHERE a.erledigt_am IS NULL AND b.aktiv = 1'
        )->fetchColumn();
    }

    /** Ein Administrator hat ein Startkennwort gesetzt: offene Anfragen des Kontos sind erledigt. */
    public function erledigeFuer(int $benutzerId, ?int $vonBenutzerId): void
    {
        $this->pdo->prepare('UPDATE kennwort_anfrage SET erledigt_am = NOW(), erledigt_von_benutzer_id = ? WHERE benutzer_id = ? AND erledigt_am IS NULL')
            ->execute([$vonBenutzerId, $benutzerId]);
    }

    /** @return bool false, wenn es keine offene Anfrage mit dieser ID gibt */
    public function verwerfe(int $anfrageId, int $vonBenutzerId): bool
    {
        $st = $this->pdo->prepare('UPDATE kennwort_anfrage SET erledigt_am = NOW(), erledigt_von_benutzer_id = ? WHERE id = ? AND erledigt_am IS NULL');
        $st->execute([$vonBenutzerId, $anfrageId]);
        return $st->rowCount() > 0;
    }

    /** @return ?array{id:int|string, benutzer_id:int|string, token_hash:string} */
    private function tokenZeile(string $token, bool $sperren): ?array
    {
        if (preg_match('/^[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }
        $hash = hash('sha256', $token);
        $st = $this->pdo->prepare(
            'SELECT t.id, t.benutzer_id, t.token_hash FROM kennwort_token t JOIN benutzer b ON b.id = t.benutzer_id '
            . 'WHERE t.token_hash = ? AND t.verwendet_am IS NULL AND t.gueltig_bis > NOW() AND b.aktiv = 1'
            . ($sperren ? ' FOR UPDATE' : '')
        );
        $st->execute([$hash]);
        $zeile = $st->fetch(\PDO::FETCH_ASSOC);
        if ($zeile === false || !hash_equals((string) $zeile['token_hash'], $hash)) {
            return null;
        }
        return $zeile;
    }

    private function anzahlVersuche(string $spalte, string $hash): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM kennwort_versuch WHERE ' . $spalte . ' = ? AND zeitpunkt > NOW() - INTERVAL 1 HOUR');
        $st->execute([$hash]);
        return (int) $st->fetchColumn();
    }

    /**
     * Je Konto höchstens eine offene Anfrage; eine weitere aktualisiert nur den Zeitpunkt. Bei einem Sperrkonflikt
     * ein zweiter Versuch; scheitert auch der, false (der Aufrufer antwortet trotzdem neutral).
     */
    private function legeAnfrageAn(int $benutzerId): bool
    {
        for ($versuch = 1; ; $versuch++) {
            // Konto gesperrt, damit zwei gleichzeitige Rückfälle keine zweite offene Anfrage anlegen.
            $this->beginneTransaktion();
            try {
                $this->pdo->prepare('SELECT id FROM benutzer WHERE id = ? FOR UPDATE')->execute([$benutzerId]);
                $st = $this->pdo->prepare('SELECT id FROM kennwort_anfrage WHERE benutzer_id = ? AND erledigt_am IS NULL ORDER BY id LIMIT 1');
                $st->execute([$benutzerId]);
                $offen = $st->fetchColumn();
                if ($offen !== false) {
                    $this->pdo->prepare('UPDATE kennwort_anfrage SET erstellt_am = NOW() WHERE id = ?')->execute([(int) $offen]);
                } else {
                    $this->pdo->prepare('INSERT INTO kennwort_anfrage (benutzer_id, erstellt_am) VALUES (?, NOW())')->execute([$benutzerId]);
                }
                $this->pdo->commit();
                return true;
            } catch (\Throwable $t) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if (!($t instanceof \PDOException) || !self::istSperrkonflikt($t)) {
                    throw $t;
                }
                if ($versuch >= 2) {
                    error_log('Kennwort vergessen: Sperrkonflikt, Anfrage an die Administratoren nicht angelegt.');
                    return false;
                }
            }
        }
    }

    /** Nächste Transaktion in READ COMMITTED (ohne Lückensperren); gilt nur für genau diese Transaktion. */
    private function beginneTransaktion(): void
    {
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->pdo->beginTransaction();
    }

    /** Deadlock (SQLSTATE 40001 bzw. MySQL 1213) oder Sperrwartezeit überschritten (1205). */
    private static function istSperrkonflikt(\PDOException $e): bool
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];
        $zustand = (string) ($info[0] ?? $e->getCode());
        $nummer = (int) ($info[1] ?? 0);
        return $zustand === '40001' || in_array($nummer, [1205, 1213], true);
    }

    /** Geheimes Salz für die IP- und E-Mail-Hashes (32 Zufallsbytes, beim ersten Bedarf erzeugt). */
    private function salz(): string
    {
        $salz = $this->einstellungen->hole(self::SALZ);
        if ($salz === null || $salz === '') {
            $this->pdo->prepare('INSERT IGNORE INTO einstellung (schluessel, wert) VALUES (?, ?)')
                ->execute([self::SALZ, bin2hex(random_bytes(32))]);
            $salz = (string) $this->einstellungen->hole(self::SALZ);
            if ($salz === '') {
                $salz = bin2hex(random_bytes(32));
                $this->einstellungen->setze(self::SALZ, $salz);
            }
        }
        return $salz;
    }

    /** @param array<string,mixed> $konto */
    private function nachricht(array $konto, string $link): Nachricht
    {
        $rufname = trim((string) $konto['vorname']);
        if ($rufname === '') {
            $rufname = trim($konto['vorname'] . ' ' . $konto['nachname']);
        }
        $text = 'Hallo ' . $rufname . ",\n\n"
            . 'für dein Konto bei ' . $this->appName . " wurde ein neues Kennwort angefordert. Über diesen Link kannst du es vergeben:\n\n"
            . $link . "\n\n"
            . "Der Link ist 60 Minuten gültig und funktioniert nur einmal. Wenn du kein neues Kennwort angefordert hast, kannst du diese E-Mail ignorieren.\n\n"
            . $this->appName . "\n";
        return new Nachricht((string) $konto['email'], 'Neues Kennwort für ' . $this->appName, $text);
    }
}
