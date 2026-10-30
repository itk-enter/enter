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

/**
 * The base class of an importer for one type of feed.
 *
 * The import reads the feed, gets the records with extractItems() and makes
 * one entity from each record. Then it upserts the entities into the broker
 * and deletes the entities of the source that the feed no longer gives.
 *
 * For example, the importer for GeoJSON feeds returns true from supports()
 * for DataType::GeoJSON. Its extractItems() returns the list in "features".
 */
abstract class AbstractSourceImporter implements SourceImporterInterface
{
    use LoggerAwareTrait;
    use LoggerTrait;

    /**
     * The attribute that holds the source ID on each entity.
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

    /**
     * Tells if this importer can import the source.
     *
     * SourceImporterFactory calls this method on each importer and uses the
     * first importer that returns true. Compare the dataType of the
     * #[Definition] of the source.
     */
    abstract public function supports(SourceInterface $source): bool;

    /**
     * Gets the records from the decoded feed.
     *
     * The import calls this method one time for each source, after it reads
     * the feed. Return the list of records. The import gives each record to
     * the createNgsiEntity() method of the source. Throw an exception if the
     * feed does not have the expected structure.
     *
     * @param iterable<mixed> $data the decoded JSON of the feed
     *
     * @return array<array<string, mixed>> the records of the feed
     */
    abstract protected function extractItems(iterable $data, SourceInterface $source): array;

    public function import(SourceInterface $source): ImportResult
    {
        $this->info('The import reads source {source}', ['source' => $source->__toString()]);

        $contextUrls = array_merge([$source->definition->contextUrl], $this->contextUrls);
        $payload = [];
        $ids = [];
        foreach ($this->read($source) as $entity) {
            if ($entity->type() !== $source->definition->model) {
                throw new \LogicException(sprintf('Source %s mapped %s onto model %s, but declares %s.', $source->definition->id, $entity->id(), $entity->type(), $source->definition->model));
            }

            $this->info('The import adds {entity} to the payload', ['entity' => $entity->id()]);
            $ids[] = $entity->id();
            $payload[] = $entity
                ->setProperty(self::SOURCE_ID_ATTRIBUTE, $source->definition->id)
                ->toPayload($contextUrls);
        }

        if (1 === count($payload)) {
            $this->info('The import upserts 1 entity');
        } else {
            $this->info('The import upserts {count} entities', ['count' => count($payload)]);
        }

        try {
            $upsert = $this->broker->upsert($payload);
        } catch (\Throwable $exception) {
            throw new UpsertFailedException($exception);
        }

        foreach ($upsert->rejected as $id => $rejection) {
            $this->warning('Broker rejected {entity}: {reason}', ['entity' => $id, 'reason' => $rejection->reason, 'error' => $rejection->error]);
        }

        try {
            $deleted = $this->sweep($source, $ids);
        } catch (\Throwable $exception) {
            throw new SweepFailedException($exception);
        }

        return new ImportResult(\count($payload), $deleted, $upsert->status, $this->broker->brokerUrl(), $upsert->rejected);
    }

    /**
     * Deletes the entities of the source that this import does not give.
     *
     * @param list<string> $ids the upserted entity IDs
     *
     * @return int the number of deleted entities
     */
    private function sweep(SourceInterface $source, array $ids): int
    {
        if ([] === $ids) {
            $this->warning('Source {source} gave no entities. The broker keeps its entities of the source', ['source' => $source->definition->id]);

            return 0;
        }

        $stale = array_values(array_diff(
            $this->broker->fetchIdsWhereAttributeEquals($source->definition->model, $source->definition->contextUrl, self::SOURCE_ID_ATTRIBUTE, $source->definition->id),
            $ids,
        ));

        if (1 === count($stale)) {
            $this->info('The import deletes 1 stale entity');
        } else {
            $this->info('The import deletes {count} stale entities', ['count' => count($stale)]);
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
            try {
                if ($entity = $source->createNgsiEntity($item, $this->transformer)) {
                    yield $entity;
                }
            } catch (\Exception $e) {
                $this->error('Skipped a record of {source}: {message}', ['source' => $source->definition->id, 'message' => $e->getMessage()]);
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
