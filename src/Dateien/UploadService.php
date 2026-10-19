<?php

declare(strict_types=1);

namespace Emc\Dateien;

use Emc\OneDrive\Uebertragung;

final class UploadService
{
    private const KATEGORIEN = ['foto', 'anleitung', 'schaltplan', 'gleisplan', 'rechnung', 'sonstiges'];

    private \Closure $istHochgeladen;
    private \Closure $verschiebe;

    /**
     * @param ?\Closure(string):bool $istHochgeladen Standard: is_uploaded_file (nur Tests ersetzen das)
     * @param ?\Closure(string,string):bool $verschiebe Standard: move_uploaded_file (nur Tests ersetzen das)
     */
    public function __construct(
        private \PDO $pdo,
        private string $speicherPfad,
        private int $maxBytes,
        private Bildbearbeitung $bild,
        ?\Closure $istHochgeladen = null,
        ?\Closure $verschiebe = null,
        private ?Uebertragung $uebertragung = null,
    ) {
        $this->istHochgeladen = $istHochgeladen ?? static fn(string $tmp): bool => is_uploaded_file($tmp);
        $this->verschiebe = $verschiebe ?? static fn(string $von, string $nach): bool => move_uploaded_file($von, $nach);
    }

    /** "8M", "1G", "512K" oder Bytes (php.ini-Kurzschreibweise); 0 bei leer/unbegrenzt. */
    public static function iniBytes(string $wert): int
    {
        $wert = trim($wert);
        if ($wert === '' || preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $wert, $m) !== 1) {
            return 0;
        }
        $zahl = (int) $m[1];
        return max(0, match (strtolower($m[2])) {
            'k' => $zahl * 1024,
            'm' => $zahl * 1048576,
            'g' => $zahl * 1073741824,
            default => $zahl,
        });
    }

    /**
     * @param array<string,mixed> $phpDatei Eintrag aus $_FILES (name, tmp_name, error, size)
     * @throws UploadFehler
     */
    public function speichere(array $phpDatei, string $titel, string $kategorie, ?int $benutzerId, ?int $fahrzeugId = null): int
    {
        $tmp = $this->pruefeUpload($phpDatei);
        $groesse = (int) filesize($tmp);
        if ($groesse > $this->maxBytes) {
            throw new UploadFehler($this->zuGross($this->maxBytes));
        }
        if ($groesse === 0) {
            throw new UploadFehler('Die Datei ist leer.');
        }

        $name = $this->bereinigeName((string) ($phpDatei['name'] ?? ''));
        $endung = $this->endung($name);
        $typ = DateiTyp::fuerEndung($endung);
        if ($typ === null) {
            throw new UploadFehler('Dateien vom Typ .' . mb_substr($endung, 0, 12) . ' sind nicht erlaubt.');
        }
        $mime = $this->erkenneMime($tmp);
        if ($mime === null || !in_array($mime, $typ['mimes'], true) || ($typ['bild'] && @getimagesize($tmp) === false)) {
            throw new UploadFehler('Der Inhalt der Datei passt nicht zur Endung .' . $endung . '.');
        }

        $titel = trim($titel) === '' ? $name : trim($titel);
        $titel = mb_substr($titel, 0, 200);
        if ($typ['bild']) {
            $kategorie = 'foto';
        } elseif (!in_array($kategorie, self::KATEGORIEN, true) || $kategorie === 'foto') {
            $kategorie = 'sonstiges';
        }

        $relativ = 'dateien/' . date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16));
        $ziel = rtrim($this->speicherPfad, '/') . '/' . $relativ;
        $ordner = dirname($ziel);
        if (!is_dir($ordner) && !@mkdir($ordner, 0750, true) && !is_dir($ordner)) {
            throw new UploadFehler('Die Datei konnte nicht gespeichert werden.');
        }
        if (!($this->verschiebe)($tmp, $ziel)) {
            throw new UploadFehler('Die Datei konnte nicht gespeichert werden.');
        }
        @chmod($ziel, 0640);

        $hatAnzeige = $typ['bild'] && $this->bild->erzeugeVarianten($ziel, $ziel);
        try {
            $this->pdo->prepare(
                'INSERT INTO dokument (titel, kategorie, original_name, endung, mime, groesse, pfad, hat_anzeige, hochgeladen_von, hochgeladen_am) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([$titel, $kategorie, $name, $endung, $mime, $groesse, $relativ, $hatAnzeige ? 1 : 0, $benutzerId]);
        } catch (\Throwable $t) {
            foreach ([$ziel, $ziel . '.anzeige.jpg', $ziel . '.vorschau.jpg'] as $f) {
                @unlink($f);
            }
            throw $t;
        }
        $id = (int) $this->pdo->lastInsertId();
        $this->uebertrageNachOneDrive($id, $fahrzeugId);
        return $id;
    }

    /**
     * Legt den Übertragungsauftrag an und versucht ihn sofort. Die Datei ist lokal bereits vollständig
     * gespeichert; jeder Fehler hier bleibt unsichtbar (nur Log ohne Geheimnisse) und wird später wiederholt.
     */
    private function uebertrageNachOneDrive(int $dokumentId, ?int $fahrzeugId): void
    {
        if ($this->uebertragung === null) {
            return;
        }
        try {
            if (!$this->uebertragung->aktiv()) {
                return;
            }
            if ($fahrzeugId !== null) {
                // Der Zielpfad richtet sich nach dem Fahrzeug, daher vor dem Auftrag zuordnen.
                $this->pdo->prepare('INSERT IGNORE INTO dokument_zuordnung (dokument_id, objekt_typ, objekt_id) VALUES (?, ?, ?)')
                    ->execute([$dokumentId, 'fahrzeug', $fahrzeugId]);
            }
            $this->uebertragung->einreihen($dokumentId);
        } catch (\Throwable $t) {
            error_log('OneDrive-Übertragung nach Upload fehlgeschlagen: ' . $t::class);
        }
    }

    /** @param array<string,mixed> $d */
    private function pruefeUpload(array $d): string
    {
        $fehler = $d['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($fehler)) {
            throw new UploadFehler('Die Datei konnte nicht hochgeladen werden.');
        }
        if ($fehler === UPLOAD_ERR_NO_FILE) {
            throw new UploadFehler('Es wurde keine Datei ausgewählt.');
        }
        if ($fehler === UPLOAD_ERR_PARTIAL) {
            throw new UploadFehler('Die Datei wurde nur teilweise hochgeladen. Bitte erneut versuchen.');
        }
        if ($fehler === UPLOAD_ERR_INI_SIZE || $fehler === UPLOAD_ERR_FORM_SIZE) {
            $ini = self::iniBytes((string) ini_get('upload_max_filesize'));
            throw new UploadFehler($this->zuGross($ini > 0 ? min($ini, $this->maxBytes) : $this->maxBytes));
        }
        $tmp = $d['tmp_name'] ?? null;
        if ($fehler !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || !($this->istHochgeladen)($tmp)) {
            throw new UploadFehler('Die Datei konnte nicht hochgeladen werden.');
        }
        return $tmp;
    }

    private function zuGross(int $bytes): string
    {
        return 'Die Datei ist zu groß (höchstens ' . max(1, intdiv($bytes, 1048576)) . ' MB).';
    }

    private function bereinigeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        return mb_substr($name, 0, 255);
    }

    /** Letzte Endung (kleingeschrieben); wirft bei fehlender Endung. */
    private function endung(string $name): string
    {
        $pos = strrpos($name, '.');
        if ($pos === false || $pos === strlen($name) - 1) {
            throw new UploadFehler('Die Datei hat keine Endung.');
        }
        return strtolower(substr($name, $pos + 1));
    }

    private function erkenneMime(string $tmp): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        return is_string($mime) ? $mime : null;
    }
}
