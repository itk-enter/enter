<?php

namespace App\SourceImporter;

use App\Source\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Get an importer for a source.
 *
 * @todo Support more importers.
 */
final readonly class SourceImporterFactory
{
    /**
     * @param iterable<SourceImporterInterface> $importers
     */
    public function __construct(
        #[AutowireIterator('app.source_importer')]
        private iterable $importers,
    ) {
    }

    public function getSourceImporter(SourceInterface $source): SourceImporterInterface
    {
        foreach ($this->importers as $importer) {
            if ($importer->supports($source)) {
                return $importer;
            }
        }

        // @todo
        throw new \RuntimeException('Cannot get importer for source');
    }
}
