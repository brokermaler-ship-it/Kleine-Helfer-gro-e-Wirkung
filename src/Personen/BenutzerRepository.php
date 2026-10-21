<?php

declare(strict_types=1);

namespace Emc\Personen;

use Emc\Auth\Rolle;
use Emc\Core\DomainFehler;

final class BenutzerRepository
{
    public const MIN_PASSWORT = 10;
    private const LETZTER_ADMIN = 'Es muss mindestens ein aktiver Administrator bleiben.';

    public function __construct(private \PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> ohne Passwort-Hash */
    public function alle(): array
    {
        return $this->vorUndNach016(
            fn(string $haken) => $this->pdo->query(self::auswahl($haken) . ' ORDER BY p.nachname, p.vorname, b.id')->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /** @return ?array<string,mixed> ohne Passwort-Hash */
    public function finde(int $id): ?array
    {
        $z = $this->vorUndNach016(function (string $haken) use ($id): array|false {
            $st = $this->pdo->prepare(self::auswahl($haken) . ' WHERE b.id = ?');
            $st->execute([$id]);
            return $st->fetch(\PDO::FETCH_ASSOC);
        });
        return $z === false ? null : $z;
    }

    private static function auswahl(string $haken): string
    {
        return 'SELECT b.id, b.person_id, b.email, b.rolle, b.aktiv, b.muss_passwort_aendern, b.letzter_login, '
            . $haken . ' AS ist_boersenwart, p.vorname, p.nachname FROM benutzer b JOIN person p ON p.id = b.person_id';
    }

    /**
     * Vor dem Datenbank-Update auf 016 fehlt die Spalte ist_boersenwart; die Benutzerverwaltung lässt sich trotzdem
     * lesen (das Häkchen gilt dann als nicht gesetzt). Speichern lässt App::handle vor dem Update ohnehin nicht zu.
     *
     * @template T
     * @param callable(string): T $aktion bekommt den SQL-Ausdruck für das Häkchen (Spalte bzw. 0)
     * @return T
     */
    private function vorUndNach016(callable $aktion): mixed
    {
        try {
            return $aktion('b.ist_boersenwart');
        } catch (\PDOException $e) {
            if ($e->getCode() !== '42S22' || !str_contains($e->getMessage(), 'ist_boersenwart')) {
                throw $e;
            }
            return $aktion('0');
        }
    }

    public function anlegen(int $personId, string $email, Rolle $rolle, string $startPasswort, bool $istBoersenwart = false): int
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            throw new DomainFehler('Bitte gib eine gültige E-Mail-Adresse an.');
        }
        $meldung = self::pruefePasswort($startPasswort);
        if ($meldung !== null) {
            throw new DomainFehler($meldung);
        }
        if ($this->existiert('SELECT COUNT(*) FROM benutzer WHERE email = ?', $email)) {
            throw new DomainFehler('Diese E-Mail-Adresse ist bereits vergeben.');
        }
        if ($this->existiert('SELECT COUNT(*) FROM benutzer WHERE person_id = ?', $personId)) {
            throw new DomainFehler('Diese Person hat bereits einen Zugang.');
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO benutzer (person_id, email, passwort_hash, rolle, aktiv, muss_passwort_aendern, ist_boersenwart) VALUES (?, ?, ?, ?, 1, 1, ?)'
            )->execute([$personId, $email, password_hash($startPasswort, PASSWORD_DEFAULT), $rolle->value, $istBoersenwart ? 1 : 0]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainFehler('Diese E-Mail-Adresse ist bereits vergeben oder die Person hat schon einen Zugang.');
            }
            throw $e;
        }
        return (int) $this->pdo->lastInsertId();
    }

    /** $istBoersenwart ist Pflicht: ein vergessenes Häkchen würde die Zusatzaufgabe stillschweigend entfernen. */
    public function aendern(int $id, Rolle $rolle, bool $aktiv, bool $istBoersenwart): void
    {
        $this->imAdminSchutz(function () use ($id, $rolle, $aktiv, $istBoersenwart): void {
            if ($rolle !== Rolle::Admin || !$aktiv) {
                $this->sichereAnderenAdmin($id);
            }
            $this->pdo->prepare('UPDATE benutzer SET rolle = ?, aktiv = ?, ist_boersenwart = ? WHERE id = ?')
                ->execute([$rolle->value, $aktiv ? 1 : 0, $istBoersenwart ? 1 : 0, $id]);
        });
    }

    /**
     * Setzt ein neues Passwort und erhöht kennwort_stand: alle bestehenden Sitzungen des Kontos enden
     * (die eigene Sitzung übernimmt den neuen Stand mit Auth::uebernimmStand).
     */
    public function passwortSetzen(int $id, #[\SensitiveParameter] string $passwort, bool $mussAendern): void
    {
        $meldung = self::pruefePasswort($passwort);
        if ($meldung !== null) {
            throw new DomainFehler($meldung);
        }
        $this->pdo->prepare('UPDATE benutzer SET passwort_hash = ?, muss_passwort_aendern = ?, kennwort_stand = kennwort_stand + 1 WHERE id = ?')
            ->execute([password_hash($passwort, PASSWORD_DEFAULT), $mussAendern ? 1 : 0, $id]);
    }

    public function passwortStimmt(int $id, string $passwort): bool
    {
        $st = $this->pdo->prepare('SELECT passwort_hash FROM benutzer WHERE id = ?');
        $st->execute([$id]);
        $hash = $st->fetchColumn();
        return is_string($hash) && password_verify($passwort, $hash);
    }

    public function loeschen(int $id): void
    {
        $this->imAdminSchutz(function () use ($id): void {
            $this->sichereAnderenAdmin($id);
            $this->pdo->prepare('DELETE FROM benutzer WHERE id = ?')->execute([$id]);
        });
    }

    public static function pruefePasswort(string $p): ?string
    {
        return mb_strlen($p) < self::MIN_PASSWORT
            ? 'Das Passwort muss mindestens ' . self::MIN_PASSWORT . ' Zeichen lang sein.'
            : null;
    }

    /** Sperrt alle aktiven Admins, damit zwei gleichzeitige Änderungen nicht beide den letzten Admin entfernen. */
    private function imAdminSchutz(callable $aktion): void
    {
        $eigeneTransaktion = !$this->pdo->inTransaction();
        if ($eigeneTransaktion) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->query("SELECT id FROM benutzer WHERE rolle = 'admin' AND aktiv = 1 FOR UPDATE")->fetchAll();
            $aktion();
            if ($eigeneTransaktion) {
                $this->pdo->commit();
            }
        } catch (\Throwable $t) {
            if ($eigeneTransaktion && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }
    }

    /** Wirft, wenn $id ein aktiver Admin ist und kein weiterer aktiver Admin existiert. */
    private function sichereAnderenAdmin(int $id): void
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM benutzer WHERE id = ? AND rolle = 'admin' AND aktiv = 1");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() === 0) {
            return; // war kein aktiver Admin
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM benutzer WHERE id <> ? AND rolle = 'admin' AND aktiv = 1");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() === 0) {
            throw new DomainFehler(self::LETZTER_ADMIN);
        }
    }

    private function existiert(string $sql, string|int $wert): bool
    {
        $st = $this->pdo->prepare($sql);
        $st->execute([$wert]);
        return (int) $st->fetchColumn() > 0;
    }
}
