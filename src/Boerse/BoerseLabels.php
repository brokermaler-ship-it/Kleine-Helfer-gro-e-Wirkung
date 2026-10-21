<?php

declare(strict_types=1);

namespace Emc\Boerse;

/** Anzeigetexte der Börse (Oberfläche und Änderungsprotokoll). */
final class BoerseLabels
{
    public const STATUS = ['angeboten' => 'angeboten', 'reserviert' => 'reserviert', 'verkauft' => 'verkauft', 'zurueck' => 'zurück'];
    public const OEFFENTLICH = ['nein' => 'nicht öffentlich', 'vorgeschlagen' => 'zur Freigabe vorgeschlagen', 'freigegeben' => 'öffentlich'];
    public const BOERSE_STATUS = ['in_vorbereitung' => 'in Vorbereitung', 'laeuft' => 'läuft', 'abgeschlossen' => 'abgeschlossen'];
    public const HERKUNFT = ['verein' => 'Verein', 'mitglied' => 'Mitglied'];
}
