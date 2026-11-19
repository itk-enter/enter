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
    /**
     * The Danish name of each data model, for the headings on /sources. A
     * model that is not in the list uses its own name.
     */
    private const array MODEL_TITLES = [
        'Bench' => 'Bænke og bordbænkesæt',
        'OffStreetParking' => 'Parkering uden for gaden',
        'ParkingSpot' => 'Parkeringspladser',
        'PublicToilet' => 'Offentlige toiletter',
    ];

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
     * The groups hold the same data sets, grouped by model. A source that a
     * merge reads is in the group of the merged data set, because the merged
     * data set is the result that a reader uses. Each group has the Danish
     * name of its model as title, and the groups are sorted by title.
     *
     * @return array{
     *     sources: list<SourceInterface>,
     *     merged: list<MergedDataSet>,
     *     groups: list<array{model: string, title: string, merged: list<MergedDataSet>, sources: list<SourceInterface>}>,
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
            'groups' => self::groups($sources, $merged),
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
     * Groups the data sets by model. A source that no merge reads is in the
     * group of its own model. If two merges read the same source, the source
     * is in the group of the first merge only, because the page shows each
     * data set one time.
     *
     * @param list<SourceInterface> $sources
     * @param list<MergedDataSet>   $merged
     *
     * @return list<array{model: string, title: string, merged: list<MergedDataSet>, sources: list<SourceInterface>}>
     */
    private static function groups(array $sources, array $merged): array
    {
        $groups = [];
        $modelOfInput = [];
        foreach ($merged as $dataSet) {
            $groups[$dataSet->model] ??= self::group($dataSet->model);
            $groups[$dataSet->model]['merged'][] = $dataSet;
            foreach ($dataSet->inputs as $id) {
                $modelOfInput[$id] ??= $dataSet->model;
            }
        }

        foreach ($sources as $source) {
            $model = $modelOfInput[$source->definition->id] ?? $source->definition->model;
            $groups[$model] ??= self::group($model);
            $groups[$model]['sources'][] = $source;
        }

        usort($groups, static fn (array $x, array $y): int => strcmp($x['title'], $y['title']));

        return $groups;
    }

    /**
     * An empty group of the data sets of a model.
     *
     * @return array{model: string, title: string, merged: list<MergedDataSet>, sources: list<SourceInterface>}
     */
    private static function group(string $model): array
    {
        return ['model' => $model, 'title' => self::MODEL_TITLES[$model] ?? $model, 'merged' => [], 'sources' => []];
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
