<?php

namespace App\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Geo\Wgs84Transformer;
use App\Import\Exception\SweepFailedException;
use App\Import\Exception\UpsertFailedException;
use App\Import\ImportResult;
use App\Ngsi\NgsiEntity;
use App\Source\SourceInterface;
use App\SourceReader\SourceReaderInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

abstract class AbstractSourceImporter implements SourceImporterInterface
{
    use LoggerAwareTrait;
    use LoggerTrait;

    /**
     * Stamped on every entity so readers can filter by source, which shared
     * models cannot tell apart. The id, unlike a URL, does not change.
     */
    public const string SOURCE_ID_ATTRIBUTE = 'sourceId';

    /**
     * @param list<string> $contextUrls
     */
    public function __construct(
        private readonly SourceReaderInterface $reader,
        private readonly NgsiLdBroker $broker,
        private readonly Wgs84Transformer $transformer,
        #[Autowire(env: 'json:APP_NGSI_CONTEXT_URLS')]
        private readonly array $contextUrls,
        LoggerInterface $logger,
    ) {
        $this->setLogger($logger);
    }

    abstract public function supports(SourceInterface $source): bool;

    /**
     * Extract items from array data from source.
     *
     * Each item must be processable by the source.
     *
     * @param iterable<mixed> $data
     *
     * @return array<array<string, mixed>>
     */
    abstract protected function extractItems(iterable $data, SourceInterface $source): array;

    public function import(SourceInterface $source): ImportResult
    {
        $this->info('Processing source {source}', ['source' => $source->__toString()]);

        $contextUrls = array_merge([$source->definition->contextUrl], $this->contextUrls);
        $payload = [];
        $ids = [];
        foreach ($this->read($source) as $entity) {
            // Fail if the entity's model is not the one the source's
            // #[Definition] declares.
            if ($entity->type() !== $source->definition->model) {
                throw new \LogicException(sprintf('Source %s mapped %s onto model %s, but declares %s.', $source->definition->id, $entity->id(), $entity->type(), $source->definition->model));
            }

            $this->info('Building payload for {entity}', ['entity' => $entity->id()]);
            $ids[] = $entity->id();
            // Stamp the entity with the id of its source.
            $payload[] = $entity
                ->setProperty(self::SOURCE_ID_ATTRIBUTE, $source->definition->id)
                ->toPayload($contextUrls);
        }

        if (1 === count($payload)) {
            $this->info('Upserting 1 entity');
        } else {
            $this->info('Upserting {count} entities', ['count' => count($payload)]);
        }

        // Write every entity to the broker.
        try {
            $upsert = $this->broker->upsert($payload);
        } catch (\Throwable $exception) {
            throw new UpsertFailedException($exception);
        }

        // Log each entity the broker rejected.
        foreach ($upsert->rejected as $id => $rejection) {
            $this->warning('Broker rejected {entity}: {reason}', ['entity' => $id, 'reason' => $rejection->reason, 'error' => $rejection->error]);
        }

        // Delete the source's entities this import did not yield.
        try {
            $deleted = $this->sweep($source, $ids);
        } catch (\Throwable $exception) {
            throw new SweepFailedException($exception);
        }

        return new ImportResult(\count($payload), $deleted, $upsert->status, $this->broker->brokerUrl(), $upsert->rejected);
    }

    /**
     * Deletes the source's entities that this import did not yield: records
     * gone from the feed, and records a split feed now sorts into another
     * source's model, which would otherwise be published twice.
     *
     * A record that failed to map is not yielded either, so it is deleted
     * too, which beats publishing what the source no longer says. An import
     * that yields nothing, sweeps nothing.
     *
     * @param list<string> $ids the entities just upserted
     *
     * @return int entities deleted
     */
    private function sweep(SourceInterface $source, array $ids): int
    {
        if ([] === $ids) {
            $this->warning('Source {source} yielded no entities; keeping what the broker holds', ['source' => $source->definition->id]);

            return 0;
        }

        // The source's entities in the broker, less those just upserted.
        $stale = array_values(array_diff(
            $this->broker->idsWhereAttributeEquals($source->definition->model, $source->definition->contextUrl, self::SOURCE_ID_ATTRIBUTE, $source->definition->id),
            $ids,
        ));

        if (1 === count($stale)) {
            $this->info('Deleting 1 stale entity');
        } else {
            $this->info('Deleting {count} stale entities', ['count' => count($stale)]);
        }

        $this->broker->delete($stale);

        return \count($stale);
    }

    /**
     * @return iterable<NgsiEntity>
     */
    private function read(SourceInterface $source): iterable
    {
        $data = $this->reader->read($source);
        $items = $this->extractItems($data, $source);

        foreach ($items as $item) {
            // Skip a record the source does not publish, and log one it
            // fails to map.
            try {
                if ($entity = $source->createNgsiEntity($item, $this->transformer)) {
                    yield $entity;
                }
            } catch (\Exception $e) {
                // @todo Log in database?
                $this->error('error: {message} ', ['message' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
