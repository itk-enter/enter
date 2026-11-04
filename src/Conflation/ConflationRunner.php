<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Ngsi\NgsiEntity;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs one merge from the broker back to the broker: fetch, the pipeline's
 * steps, create and publish.
 *
 * To publish, the runner upserts every merged entity. Then it deletes the
 * entities of the merged data set that this run did not produce, for example
 * a toilet whose records are no longer near each other. If a step breaks its
 * limits, the run fails before it publishes anything.
 */
final readonly class ConflationRunner
{
    /**
     * @param list<string> $contextUrls the contexts that the runner attaches to every entity, in addition to the
     *                                  context of the result model
     */
    public function __construct(
        private ConflationFetcher $fetcher,
        private ConflationPipeline $pipeline,
        private MergedDataSetFactory $dataSets,
        private MergedEntityFactory $entities,
        private NgsiLdBroker $broker,
        private LoggerInterface $logger,
        #[Autowire(env: 'json:APP_NGSI_CONTEXT_URLS')]
        private array $contextUrls,
    ) {
    }

    /**
     * @param bool $publish false to stop after the runner creates the entities, and to publish nothing
     */
    public function run(Conflation $conflation, bool $publish = true): ConflationRun
    {
        $dataSet = $this->dataSets->create($conflation);
        $records = $this->fetcher->fetch($conflation);
        $merged = $this->pipeline->run($conflation, $records);
        $entities = array_map(fn (MergedEntity $entity): NgsiEntity => $this->entities->create($conflation, $entity), $merged);

        $run = new ConflationRun($dataSet, \count($records), $merged);
        if (!$publish) {
            return $run;
        }

        $contextUrls = array_merge([$dataSet->contextUrl], $this->contextUrls);
        $this->broker->upsert(array_map(static fn (NgsiEntity $entity): array => $entity->toPayload($contextUrls), $entities));

        $published = array_map(static fn (array $feature): mixed => $feature['id'] ?? null, iterator_to_array($this->broker->features($dataSet->id, $dataSet->contextUrl), false));
        $stale = array_values(array_diff(array_filter($published, \is_string(...)), array_map(static fn (NgsiEntity $entity): string => $entity->id(), $entities)));
        $this->broker->delete($stale);

        $this->logger->info('Merge {merge} published {count} entities and deleted {stale}.', ['merge' => $conflation->id, 'count' => \count($entities), 'stale' => \count($stale)]);

        return $run->published(\count($entities), \count($stale));
    }
}
