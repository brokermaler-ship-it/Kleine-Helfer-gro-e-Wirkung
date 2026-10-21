<?php

declare(strict_types=1);

namespace Emc\Boerse;

use Emc\Betrieb\Einstellungen;

/**
 * Schutz der öffentlichen Formulare: unsichtbares Fangfeld, signierte Zeitmarke (Mindestausfüllzeit), Begrenzung je
 * IP-Adresse. Die IP wird nur als HMAC mit einem geheimen Salz der Instanz gespeichert (Muster KennwortVergessen,
 * eigenes Salz) und nach 24 Stunden gelöscht.
 */
final class Spamschutz
{
    public const SALZ = 'boerse_ip_salz';
    public const MIN_SEKUNDEN = 3;
    public const MAX_JE_STUNDE = 5;
    /** Obergrenze angenommener Anfragen je Stunde für die ganze Instanz (schützt Webspace und Rechenzeit vor verteilten Bots). */
    public const MAX_GESAMT_JE_STUNDE = 60;
    public const AUFBEWAHRUNG_STUNDEN = 24;
    public const FANGFELD = 'webseite';
    public const ZEITFELD = 'zeitmarke';

    private ?string $salz = null;

    public function __construct(private \PDO $pdo, private Einstellungen $einstellungen)
    {
    }

    /**
     * „<unix>.<hmac>“ – Zeitpunkt, zu dem das Formular ausgeliefert wurde, gegen Fälschung signiert und an die Sitzung
     * gebunden ($bindung = CSRF-Token): eine Marke aus einer anderen Sitzung gilt nicht, ein Bot muss je Sitzung warten.
     */
    public function zeitmarke(\DateTimeImmutable $jetzt, string $bindung = ''): string
    {
        $zeit = (string) $jetzt->getTimestamp();
        return $zeit . '.' . $this->signatur($zeit, $bindung);
    }

    /**
     * Fangfeld leer (oder nicht gesendet), Zeitmarke gültig signiert, mindestens 3 Sekunden und höchstens 24 Stunden alt.
     *
     * @param array<string,mixed> $post
     */
    public function istMensch(array $post, \DateTimeImmutable $jetzt, string $bindung = ''): bool
    {
        $fang = $post[self::FANGFELD] ?? '';
        if (!is_string($fang) || $fang !== '') {
            return false;
        }
        $marke = $post[self::ZEITFELD] ?? null;
        if (!is_string($marke) || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $marke, $m) !== 1) {
            return false;
        }
        if (!hash_equals($this->signatur($m[1], $bindung), $m[2])) {
            return false;
        }
        $alter = $jetzt->getTimestamp() - (int) $m[1];
        return $alter >= self::MIN_SEKUNDEN && $alter <= self::AUFBEWAHRUNG_STUNDEN * 3600;
    }

    /** HMAC der IP-Adresse mit dem Salz; IPv6 zählt je /56-Netz (ein Anschluss bekommt meist ein /56 oder mehr). */
    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', self::netz($ip), $this->salz());
    }

    /** IPv4 unverändert, IPv6 als /56-Präfix („2001:db8:1::/56“); Unlesbares unverändert. */
    public static function netz(string $ip): string
    {
        if (!str_contains($ip, ':')) {
            return $ip;
        }
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12)); // IPv4 in IPv6 (::ffff:203.0.113.7)
        }
        return (string) inet_ntop(substr($bin, 0, 7) . str_repeat("\0", 9)) . '/56';
    }

    /** Mindestens MAX_JE_STUNDE Anfragen dieser IP oder MAX_GESAMT_JE_STUNDE insgesamt in der Stunde vor $jetzt. */
    public function begrenzt(string $ipHash, \DateTimeImmutable $jetzt): bool
    {
        $seit = $jetzt->modify('-1 hour')->format('Y-m-d H:i:s');
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM boerse_anfrage_sperre WHERE ip_hash = ? AND zeitpunkt > ?');
        $st->execute([$ipHash, $seit]);
        if ((int) $st->fetchColumn() >= self::MAX_JE_STUNDE) {
            return true;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM boerse_anfrage_sperre WHERE zeitpunkt > ?');
        $st->execute([$seit]);
        return (int) $st->fetchColumn() >= self::MAX_GESAMT_JE_STUNDE;
    }

    public function zaehle(string $ipHash, \DateTimeImmutable $jetzt): void
    {
        $this->pdo->prepare('INSERT INTO boerse_anfrage_sperre (ip_hash, zeitpunkt) VALUES (?, ?)')
            ->execute([$ipHash, $jetzt->format('Y-m-d H:i:s')]);
    }

    /** Löscht Sperrzeilen, die älter als 24 Stunden sind. */
    public function raeumeAuf(\DateTimeImmutable $jetzt): void
    {
        $this->pdo->prepare('DELETE FROM boerse_anfrage_sperre WHERE zeitpunkt < ?')
            ->execute([$jetzt->modify('-' . self::AUFBEWAHRUNG_STUNDEN . ' hours')->format('Y-m-d H:i:s')]);
    }

    /**
     * Sperrt die Zeile des Salzes (SELECT … FOR UPDATE) in der laufenden Transaktion: so laufen alle öffentlichen
     * Anfragen nacheinander, und parallele Anfragen derselben IP überschreiten die Begrenzung nicht.
     */
    public function sperre(): void
    {
        $this->salz();
        $this->pdo->prepare('SELECT wert FROM einstellung WHERE schluessel = ? FOR UPDATE')->execute([self::SALZ]);
    }

    private function signatur(string $zeit, string $bindung): string
    {
        return hash_hmac('sha256', 'boerse-zeitmarke|' . $zeit . '|' . $bindung, $this->salz());
    }

    /** Geheimes Salz der Instanz (32 Zufallsbytes hex), beim ersten Bedarf per INSERT IGNORE angelegt. */
    private function salz(): string
    {
        if ($this->salz !== null) {
            return $this->salz;
        }
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
        return $this->salz = $salz;
    }
}
