<?php

namespace App\SourceImporter;

use App\Import\ImportResult;
use App\Source\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Converts data from a source to NGSI-LD and upserts it into the broker.
 */
#[AutoconfigureTag('app.source_importer')]
interface SourceImporterInterface
{
    public function supports(SourceInterface $source): bool;

    public function import(SourceInterface $source): ImportResult;
}
