<?php

namespace App\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Geo\Wgs84Transformer;
use App\Import\Exception\UpsertFailedException;
use App\Import\ImportResult;
use App\Ngsi\NgsiEntity;
use App\Source\SourceInterface;
use App\SourceReader\SourceReaderInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

abstract class AbstracSourceImporter implements SourceImporterInterface
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
        foreach ($this->read($source) as $entity) {
            $this->info('Building payload for {entity}', ['entity' => $entity->id()]);
            $payload[] = $entity
                ->setProperty(self::SOURCE_ID_ATTRIBUTE, $source->definition->id)
                ->toPayload($contextUrls);
        }

        if (1 === count($payload)) {
            $this->info('Upserting 1 entity');
        } else {
            $this->info('Upserting {count} entities', ['count' => count($payload)]);
        }

        try {
            $status = $this->broker->upsert($payload);
        } catch (\Throwable $exception) {
            throw new UpsertFailedException($exception);
        }

        return new ImportResult(\count($payload), $status, $this->broker->brokerUrl());
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
