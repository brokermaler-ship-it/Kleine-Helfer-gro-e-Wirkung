<?php

declare(strict_types=1);

namespace Emc\Reparatur;

use Emc\Core\Session;
use Emc\Dateien\DateiController;
use Emc\Dateien\DateiTyp;
use Emc\Dateien\DokumentRepository;
use Emc\Dateien\UploadFehler;
use Emc\Dateien\UploadService;

/** Fotos an einer Reparatur ablegen (beim Melden und auf der Reparaturseite). */
final class ReparaturFotos
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
     * @param string $wofuer „eine Meldung“ bzw. „eine Reparatur“ (für die Warnung)
     * @return list<array{id:int, name:string}> gespeicherte Fotos
     */
    public function speichere(mixed $dateien, int $reparaturId, ?int $benutzerId, string $wofuer): array
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
                $id = $this->upload->speichere($datei, '', 'foto', $benutzerId, null, null, null, $reparaturId);
            } catch (UploadFehler $f) {
                $this->session->meldung('warnung', ($name !== '' ? $name . ': ' : '') . $f->getMessage());
                continue;
            }
            $this->dokumente->zuordnen($id, 'reparatur', $reparaturId);
            $gespeichert[] = ['id' => $id, 'name' => $name];
        }
        if (count($dateien) > self::MAX_FOTOS) {
            $this->session->meldung('warnung', 'Es werden höchstens ' . self::MAX_FOTOS . ' Fotos auf einmal verarbeitet.');
        }
        return $gespeichert;
    }
}
