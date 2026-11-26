<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\ImportResult;
use App\Import\SourcesImporter;
use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use App\SourceImporter\SourceImporterInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * A source importer that reads no feed and calls no broker.
 *
 * The import of a source in $failing throws an exception. The import of each
 * other source upserts 3 entities and deletes 1 stale entity.
 */
final class FakeSourceImporter implements SourceImporterInterface
{
    /**
     * The ids of the sources that the importer imported.
     *
     * @var list<string>
     */
    public array $imported = [];

    /**
     * @param list<string> $failing the ids of the sources whose import fails
     */
    public function __construct(private readonly array $failing = [])
    {
    }

    public function supports(SourceInterface $source): bool
    {
        return true;
    }

    public function import(SourceInterface $source): ImportResult
    {
        $id = $source->definition->id;
        if (\in_array($id, $this->failing, true)) {
            throw new \RuntimeException(\sprintf('The feed of %s is not available.', $id));
        }
        $this->imported[] = $id;

        return new ImportResult(3, 1, 201, 'http://broker.example/', str_repeat('a', 64));
    }

    /**
     * Gets a SourcesImporter that uses this importer for each source.
     */
    public function sourcesImporter(): SourcesImporter
    {
        return new SourcesImporter(new SourceImporterFactory([$this]), new EventDispatcher());
    }
}
