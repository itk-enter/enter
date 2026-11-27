<?php

declare(strict_types=1);

namespace App;

use App\Broker\NgsiLdBroker;
use App\Conflation\ConflationManager;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Io\MergedDataSetFactory;
use App\Source\SourceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Gets the data sets for the /sources page. The key of each item in
     * entitiesUrls is the ID of a data set.
     *
     * The groups hold the same data sets, grouped by model. A source that a
     * merge reads is in the group of the merged data set, because the merged
     * data set is the result that a reader uses. Each group has the name of
     * its model in the language of the request as title, from the translation
     * key "model.<model>". A model without a translation uses its own name.
     * The groups are sorted by title.
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
            'groups' => $this->groups($sources, $merged),
            'entitiesUrls' => $entitiesUrls,
        ];
    }

    /**
     * Gets a merged data set by its id, or null if no merge has the id.
     */
    public function mergedDataSet(string $id): ?MergedDataSet
    {
        $conflation = $this->conflations->getConflations()[$id] ?? null;

        return null === $conflation ? null : $this->mergedDataSets->create($conflation);
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
     * Gets one data set as an item of /sources.json.
     *
     * @return array<string, mixed>|null the data set, or null if no data set has the id
     */
    public function find(string $id): ?array
    {
        $source = $this->sources->getSources()[$id] ?? null;
        $conflation = $this->conflations->getConflations()[$id] ?? null;

        $dataSet = match (true) {
            null !== $source => $source->toArray(),
            null !== $conflation => $this->mergedDataSets->create($conflation)->toArray(),
            default => null,
        };

        return null === $dataSet ? null : [...$dataSet, 'entities_url' => $this->entitiesUrl($id)];
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
    private function groups(array $sources, array $merged): array
    {
        $groups = [];
        $modelOfInput = [];
        foreach ($merged as $dataSet) {
            $groups[$dataSet->model] ??= $this->group($dataSet->model);
            $groups[$dataSet->model]['merged'][] = $dataSet;
            foreach ($dataSet->inputs as $id) {
                $modelOfInput[$id] ??= $dataSet->model;
            }
        }

        foreach ($sources as $source) {
            $model = $modelOfInput[$source->definition->id] ?? $source->definition->model;
            $groups[$model] ??= $this->group($model);
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
    private function group(string $model): array
    {
        // The translator gives the key back if the model has no translation.
        $key = 'model.'.$model;
        $title = $this->translator->trans($key);

        return ['model' => $model, 'title' => $key === $title ? $model : $title, 'merged' => [], 'sources' => []];
    }

    /**
     * Gets the broker URL of the entities of a data set.
     */
    private function entitiesUrl(string $dataSetId): string
    {
        return $this->urlGenerator->generate('ngsi_ld_v1_request', [
            'path' => 'entities',
            'q' => NgsiLdBroker::sourceQuery($dataSetId),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
