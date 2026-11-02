<?php

declare(strict_types=1);

namespace App;

use App\Conflation\ConflationManager;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Io\MergedDataSetFactory;
use App\Source\SourceInterface;
use App\SourceImporter\AbstractSourceImporter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lists the data sets that the app publishes: first the sources, then the
 * merged data sets. Each data set has the broker URL of its entities.
 */
final readonly class DataSetCatalog
{
    public function __construct(
        private SourceManager $sources,
        private ConflationManager $conflations,
        private MergedDataSetFactory $mergedDataSets,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Gets the data sets for the /sources page. The key of each item in
     * entitiesUrls is the ID of a data set.
     *
     * @return array{
     *     sources: list<SourceInterface>,
     *     merged: list<MergedDataSet>,
     *     entitiesUrls: array<string, string>,
     * }
     */
    public function all(): array
    {
        $sources = array_values($this->sources->getSources());
        $merged = array_map($this->mergedDataSets->create(...), array_values($this->conflations->getConflations()));

        $entitiesUrls = [];
        foreach ($sources as $source) {
            $entitiesUrls[$source->definition->id] = $this->entitiesUrl($source->definition->id);
        }
        foreach ($merged as $dataSet) {
            $entitiesUrls[$dataSet->id] = $this->entitiesUrl($dataSet->id);
        }

        return [
            'sources' => $sources,
            'merged' => $merged,
            'entitiesUrls' => $entitiesUrls,
        ];
    }

    /**
     * Gets the data sets for /sources.json.
     *
     * @return list<array<string, mixed>> one item for each data set, with
     *                                    the key entities_url
     */
    public function toArray(): array
    {
        ['sources' => $sources, 'merged' => $merged, 'entitiesUrls' => $entitiesUrls] = $this->all();

        return [
            ...array_map(
                static fn (SourceInterface $source): array => [
                    ...$source->toArray(),
                    'entities_url' => $entitiesUrls[$source->definition->id],
                ],
                $sources,
            ),
            ...array_map(
                static fn (MergedDataSet $dataSet): array => [
                    ...$dataSet->toArray(),
                    'entities_url' => $entitiesUrls[$dataSet->id],
                ],
                $merged,
            ),
        ];
    }

    /**
     * Gets the broker URL of the entities of a data set.
     */
    private function entitiesUrl(string $dataSetId): string
    {
        return $this->urlGenerator->generate('ngsi_ld_v1_request', [
            'path' => 'entities',
            'q' => \sprintf('%s=="%s"', AbstractSourceImporter::SOURCE_ID_ATTRIBUTE, $dataSetId),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
