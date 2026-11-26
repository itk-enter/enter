<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\Lookup\RemoteLookups;
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
 *
 * Before it publishes, the runner keeps the answers of the lookups of the
 * merge from the entities that it published before, and marks the checks of
 * the lookups that are due. After it publishes, it requests these checks.
 *
 * runAll() runs several merges. If a merge fails, runAll() logs the error and
 * continues with the next merge.
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
        private RemoteLookups $lookups,
        private LoggerInterface $logger,
        #[Autowire(env: 'json:APP_NGSI_CONTEXT_URLS')]
        private array $contextUrls,
    ) {
    }

    /**
     * Runs each merge, also after a merge failed.
     *
     * @param iterable<Conflation>                                        $conflations
     * @param bool                                                        $publish     false to publish nothing
     * @param (callable(Conflation, ConflationRun|\Throwable): void)|null $onEach      called after each merge
     *
     * @return array<string, \Throwable> the errors of the merges that failed, by merge id
     */
    public function runAll(iterable $conflations, bool $publish = true, ?callable $onEach = null): array
    {
        $failed = [];

        foreach ($conflations as $conflation) {
            try {
                $result = $this->run($conflation, $publish);
            } catch (\Throwable $exception) {
                $result = $failed[$conflation->id] = $exception;
                $this->logger->error('Merge {merge} failed: {message}', ['merge' => $conflation->id, 'message' => $exception->getMessage(), 'exception' => $exception]);
            }

            if (null !== $onEach) {
                $onEach($conflation, $result);
            }
        }

        return $failed;
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

        $wanted = $this->lookups->keepAnswers($conflation, $dataSet, $entities);
        $due = $this->lookups->markDueChecks($entities, $wanted);

        $contextUrls = array_merge([$dataSet->contextUrl], $this->contextUrls);
        $this->broker->upsert(array_map(static fn (NgsiEntity $entity): array => $entity->toPayload($contextUrls), $entities));

        $published = array_map(static fn (array $feature): mixed => $feature['id'] ?? null, iterator_to_array($this->broker->features($dataSet->id, $dataSet->contextUrl), false));
        $stale = array_values(array_diff(array_filter($published, \is_string(...)), array_map(static fn (NgsiEntity $entity): string => $entity->id(), $entities)));
        $this->broker->delete($stale);

        $checks = $this->lookups->requestChecks($dataSet, $entities, $due);

        $this->logger->info('Merge {merge} published {count} entities, deleted {stale} and requested {checks} lookups.', ['merge' => $conflation->id, 'count' => \count($entities), 'stale' => \count($stale), 'checks' => $checks]);

        return $run->published(\count($entities), \count($stale));
    }
}
