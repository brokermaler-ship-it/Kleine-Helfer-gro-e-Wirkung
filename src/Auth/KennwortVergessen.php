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
    private const SALZ = 'kennwort_ip_salz';

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

    /** Hash, unter dem eine E-Mail-Adresse in `kennwort_versuch` steht (auch für die DSGVO-Löschung). */
    public static function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Nimmt eine Anfrage entgegen. Rückgabe: der Link nur in der Testumgebung ohne Mailer und nur bei bekanntem
     * aktivem Konto, sonst immer null – Aufrufer zeigen unabhängig davon dieselbe Antwort (ANTWORT).
     */
    public function beantrage(string $email, string $ip): ?string
    {
        $email = mb_strtolower(trim($email));
        $emailHash = self::emailHash($email);
        $ipHash = hash_hmac('sha256', $ip, $this->salz());

        $this->pdo->exec('DELETE FROM kennwort_versuch WHERE zeitpunkt < NOW() - INTERVAL ' . self::AUFBEWAHRUNG_STUNDEN . ' HOUR');
        $this->pdo->exec('DELETE FROM kennwort_token WHERE gueltig_bis < NOW() - INTERVAL ' . self::AUFBEWAHRUNG_STUNDEN . ' HOUR');

        if ($this->anzahlVersuche('email_hash', $emailHash) >= self::MAX_JE_EMAIL
            || $this->anzahlVersuche('ip_hash', $ipHash) >= self::MAX_JE_IP
        ) {
            return null; // still nichts tun und nicht zählen
        }
        $this->pdo->prepare('INSERT INTO kennwort_versuch (email_hash, ip_hash, zeitpunkt) VALUES (?, ?, NOW())')
            ->execute([$emailHash, $ipHash]);

        $konto = null;
        if ($email !== '' && mb_strlen($email) <= 190) {
            $st = $this->pdo->prepare(
                'SELECT b.id, b.email, p.vorname, p.nachname FROM benutzer b JOIN person p ON p.id = b.person_id WHERE b.email = ? AND b.aktiv = 1'
            );
            $st->execute([$email]);
            $konto = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        // Token in beiden Fällen erzeugen, damit bekannte und unbekannte Adressen vergleichbar viel Arbeit machen.
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        if ($konto === null) {
            // Gleichartige Abfragen wie bei einem bekannten Konto (Tokens aufräumen, offene Anfrage suchen), ändern nichts.
            $this->pdo->prepare('DELETE FROM kennwort_token WHERE token_hash = ? AND verwendet_am IS NULL')->execute([$tokenHash]);
            $this->pdo->prepare('SELECT id FROM kennwort_anfrage WHERE benutzer_id = 0 AND erledigt_am IS NULL LIMIT 1')->execute();
            // Nur gezählt, ohne Adresse: niemand soll Fremdadressen in die Verwaltung einschleusen können.
            $this->protokoll->schreibe(null, 'kennwort_anfrage', 0, 'angelegt', ['adresse' => ['alt' => null, 'neu' => 'unbekannt']]);
            return null;
        }

        $id = (int) $konto['id'];
        $this->pdo->prepare('DELETE FROM kennwort_token WHERE benutzer_id = ? AND verwendet_am IS NULL')->execute([$id]);
        $link = $this->linkBasis . '/kennwort/neu?t=' . $token;
        // Ohne Postfach braucht nur die Testinstanz einen Token (sie zeigt den Link an).
        if ($this->mailer !== null || $this->testumgebung) {
            $this->pdo->prepare(
                'INSERT INTO kennwort_token (benutzer_id, token_hash, gueltig_bis) VALUES (?, ?, NOW() + INTERVAL ' . self::TOKEN_MINUTEN . ' MINUTE)'
            )->execute([$id, $tokenHash]);
        }

        if ($this->mailer !== null) {
            try {
                $this->mailer->sende($this->nachricht($konto, $link));
                $this->protokoll->schreibe(null, 'benutzer', $id, 'geaendert', ['passwort' => ['alt' => null, 'neu' => 'Link „Kennwort vergessen“ per E-Mail verschickt']]);
                return null;
            } catch (\Throwable $t) {
                // Rückfall auf die Admin-Anfrage. Nie Adresse, Token oder Zugangsdaten ins Fehlerprotokoll.
                $this->pdo->prepare('DELETE FROM kennwort_token WHERE token_hash = ?')->execute([$tokenHash]);
                error_log('Kennwort vergessen: E-Mail-Versand gescheitert (' . $t::class . ': ' . ($t instanceof \Emc\Mail\MailFehler ? $t->getMessage() : 'unerwarteter Fehler') . '), Anfrage an die Administratoren angelegt.');
            }
        }

        $this->legeAnfrageAn($id);
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
        $this->pdo->beginTransaction();
        try {
            $zeile = $this->tokenZeile($token, true);
            if ($zeile === null) {
                throw new DomainFehler(self::UNGUELTIG);
            }
            $regel = BenutzerRepository::pruefePasswort($kennwort);
            if ($regel !== null) {
                throw new DomainFehler($regel);
            }
            $id = (int) $zeile['benutzer_id'];
            $this->pdo->prepare('UPDATE kennwort_token SET verwendet_am = NOW() WHERE id = ?')->execute([(int) $zeile['id']]);
            $this->pdo->prepare('DELETE FROM kennwort_token WHERE benutzer_id = ? AND id <> ?')->execute([$id, (int) $zeile['id']]);
            (new BenutzerRepository($this->pdo))->passwortSetzen($id, $kennwort, false);
            $this->pdo->prepare('UPDATE benutzer SET kennwort_stand = kennwort_stand + 1 WHERE id = ?')->execute([$id]);
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

    /** @return list<array{id:int, benutzer_id:int, email:string, name:string, erstellt_am:string}> älteste zuerst */
    public function offeneAnfragen(): array
    {
        $zeilen = $this->pdo->query(
            'SELECT a.id, a.benutzer_id, b.email, p.vorname, p.nachname, a.erstellt_am FROM kennwort_anfrage a '
            . 'JOIN benutzer b ON b.id = a.benutzer_id JOIN person p ON p.id = b.person_id '
            . 'WHERE a.erledigt_am IS NULL ORDER BY a.erstellt_am, a.id'
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
        return (int) $this->pdo->query('SELECT COUNT(*) FROM kennwort_anfrage WHERE erledigt_am IS NULL')->fetchColumn();
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

    /** Je Konto höchstens eine offene Anfrage; eine weitere aktualisiert nur den Zeitpunkt. */
    private function legeAnfrageAn(int $benutzerId): void
    {
        $st = $this->pdo->prepare('SELECT id FROM kennwort_anfrage WHERE benutzer_id = ? AND erledigt_am IS NULL ORDER BY id LIMIT 1');
        $st->execute([$benutzerId]);
        $offen = $st->fetchColumn();
        if ($offen !== false) {
            $this->pdo->prepare('UPDATE kennwort_anfrage SET erstellt_am = NOW() WHERE id = ?')->execute([(int) $offen]);
        } else {
            $this->pdo->prepare('INSERT INTO kennwort_anfrage (benutzer_id, erstellt_am) VALUES (?, NOW())')->execute([$benutzerId]);
        }
    }

    /** Geheimes Salz für die IP-Hashes (32 Zufallsbytes, beim ersten Bedarf erzeugt). */
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
