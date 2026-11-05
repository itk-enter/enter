<?php

namespace App\SourceImporter;

use App\Source\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Gets the importer for a source.
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

        throw new \RuntimeException(sprintf('No importer can import the source %s.', $source->definition->id));
    }
}
