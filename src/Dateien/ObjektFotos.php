<?php

declare(strict_types=1);

namespace Emc\Dateien;

use Emc\Core\Session;

/**
 * Fotos an einem Objekt ablegen, das nur Fotos kennt (Reparatur, Magazinartikel, Börsenangebot): nur Bilder, Upload
 * über den UploadService (Uploadgrenzen, Varianten, OneDrive), Zuordnung über dokument_zuordnung. Bei Objekten mit
 * Titelbild wird das erste Foto Titelbild, solange keins gesetzt ist.
 */
final class ObjektFotos
{
    public const MAX_FOTOS = 20;

    public function __construct(
        private UploadService $upload,
        private DokumentRepository $dokumente,
        private Session $session,
    ) {
    }

    /**
     * Nur Fotos; andere Dateien und Upload-Fehler ergeben eine Warnung, der Rest wird trotzdem gespeichert.
     *
     * @param mixed $dateien $_FILES-Eintrag (einzeln oder mehrfach)
     * @param string $objektTyp reparatur, magazin_artikel oder boerse_angebot
     * @param string $wofuer z. B. „eine Reparatur“ bzw. „einen Magazinartikel“ (für die Warnung)
     * @return list<array{id:int, name:string}> gespeicherte Fotos
     */
    public function speichere(mixed $dateien, string $objektTyp, int $objektId, ?int $benutzerId, string $wofuer): array
    {
        $dateien = DateiController::normalisiere($dateien);
        $gespeichert = [];
        foreach (array_slice($dateien, 0, self::MAX_FOTOS) as $datei) {
            $name = is_string($datei['name'] ?? null) ? basename(str_replace('\\', '/', $datei['name'])) : '';
            $punkt = strrpos($name, '.');
            $dateiTyp = $punkt === false ? null : DateiTyp::fuerEndung(substr($name, $punkt + 1));
            if ($dateiTyp === null || !$dateiTyp['bild']) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . 'Nur Fotos (JPEG, PNG oder WebP) können an ' . $wofuer . ' angehängt werden.');
                continue;
            }
            try {
                $id = $objektTyp === 'reparatur'
                    ? $this->upload->speichere($datei, '', 'foto', $benutzerId, null, null, null, $objektId)
                    : $this->upload->speichere($datei, '', 'foto', $benutzerId, null, null, null, null, $objektTyp, $objektId);
            } catch (UploadFehler $f) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . $f->getMessage());
                continue;
            }
            $this->dokumente->zuordnen($id, $objektTyp, $objektId);
            if (DokumentRepository::hatTitelbild($objektTyp) && (int) ($this->dokumente->finde($id)['hat_anzeige'] ?? 0) === 1) {
                $this->dokumente->setzeTitelbildWennLeer($objektTyp, $objektId, $id);
            }
            $gespeichert[] = ['id' => $id, 'name' => $name];
        }
        if (count($dateien) > self::MAX_FOTOS) {
            $this->session->meldung('warnung', 'Es werden höchstens ' . self::MAX_FOTOS . ' Fotos auf einmal verarbeitet.');
        }
        return $gespeichert;
    }
}
