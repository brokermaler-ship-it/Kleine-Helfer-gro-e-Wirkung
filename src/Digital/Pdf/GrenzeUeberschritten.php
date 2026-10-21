<?php

declare(strict_types=1);

namespace Emc\Digital\Pdf;

/**
 * Bricht das Einlesen eines PDFs ab, sobald eine Grenze erreicht ist. Bewusst ein \Error: die Bibliothek fängt beim
 * Entpacken nur \Exception ab und würde sonst einfach weiterlesen.
 */
final class GrenzeUeberschritten extends \Error
{
}
