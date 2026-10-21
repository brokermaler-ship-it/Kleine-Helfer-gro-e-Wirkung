<?php

declare(strict_types=1);

namespace Emc\Digital\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\FilterHelper;
use Smalot\PdfParser\RawData\RawDataParser;

/**
 * Parser der Bibliothek mit zählendem Entpacken und einer Höchstzahl an Objekten (auch aus Objektströmen).
 */
final class BegrenzterParser extends Parser
{
    public readonly ZaehlenderFilterHelper $filter;

    public function __construct(Config $config, int $entpackBudget, private int $maxObjekte)
    {
        parent::__construct([], $config);
        $this->filter = new ZaehlenderFilterHelper($entpackBudget);
        $this->rawDataParser = new class ([], $config, $this->filter) extends RawDataParser {
            public function __construct(array $cfg, Config $config, FilterHelper $filter)
            {
                parent::__construct($cfg, $config);
                $this->filterHelper = $filter;
            }
        };
    }

    protected function parseObject(string $id, array $structure, ?Document $document)
    {
        parent::parseObject($id, $structure, $document);
        if (count($this->objects) > $this->maxObjekte) {
            throw new GrenzeUeberschritten('Zu viele Objekte');
        }
    }
}
