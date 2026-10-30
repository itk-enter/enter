<?php

declare(strict_types=1);

namespace App\Conflation\Io;

use App\Conflation\Conflation;
use App\Source\SourceInterface;

/**
 * Describes the data set a merge publishes.
 *
 * The title and description are the merge's own. The context and the licence
 * follow from the data sets it reads, unless the merge names them:
 *
 * - Context: the one that the input data sets of the result model declare.
 *   All four toilet data sets declare the same PublicToilet context, so a
 *   toilet merge does not have to name one. If they declare different
 *   contexts, for example two versions of the model, the merge must name one.
 * - Licence: the one that every input data set states. If an input data set
 *   states no licence, or the input data sets state different licences, the
 *   licence is unsettled (null). It stays unsettled until the merge names
 *   one, as with a source that states no licence.
 */
final readonly class MergedDataSetFactory
{
    public function __construct(
        private ConflationFetcher $fetcher,
    ) {
    }

    public function create(Conflation $conflation): MergedDataSet
    {
        $inputs = $this->fetcher->dataSets($conflation);

        return new MergedDataSet(
            $conflation->id,
            $conflation->title,
            $conflation->description,
            $conflation->resultModel,
            $conflation->contextUrl ?? $this->contextUrl($conflation, $inputs),
            $conflation->licence ?? $this->licence($inputs),
            array_map(static fn (SourceInterface $source): string => $source->definition->id, $inputs),
        );
    }

    /**
     * @param list<SourceInterface> $inputs
     */
    private function contextUrl(Conflation $conflation, array $inputs): string
    {
        $contextUrls = array_values(array_unique(array_map(
            static fn (SourceInterface $source): string => $source->definition->contextUrl,
            array_filter($inputs, static fn (SourceInterface $source): bool => $source->definition->model === $conflation->resultModel),
        )));

        if (1 !== \count($contextUrls)) {
            throw new \LogicException(\sprintf('Merge %s reads %d data sets of %s that declare %d contexts, so it has to name its contextUrl.', $conflation->id, \count($inputs), $conflation->resultModel, \count($contextUrls)));
        }

        return $contextUrls[0];
    }

    /**
     * @param list<SourceInterface> $inputs
     */
    private function licence(array $inputs): ?string
    {
        $licences = array_unique(array_map(static fn (SourceInterface $source): ?string => $source->definition->licence, $inputs));

        return 1 === \count($licences) ? reset($licences) : null;
    }
}
