<?php

declare(strict_types=1);

namespace App\Broker;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Writes entities to an NGSI-LD context broker.
 *
 * Uses the batch upsert operation so a re-import updates the entities it
 * already created instead of failing on 409 Conflict. That makes the whole
 * import idempotent, which matters because the entity ids are derived from
 * the source's own primary key.
 */
final readonly class NgsiLdBroker
{
    private const string UPSERT_PATH = '/ngsi-ld/v1/entityOperations/upsert';
    private const string BATCH_DELETE_PATH = '/ngsi-ld/v1/entityOperations/delete';
    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * The most entities the broker hands out per request; Scorpio refuses a
     * larger limit unless its scorpio.entity.max-limit is raised.
     */
    private const int PAGE_SIZE = 1000;

    /**
     * The most entities sent in one batch operation, per Scorpio's
     * scorpio.entity.batch-operations.*.max.
     */
    private const int BATCH_SIZE = 1000;

    /**
     * The payload carries its own @context, so it must be sent as
     * application/ld+json. Sending application/json instead requires the
     * context in a Link header, and brokers reject the mismatch.
     */
    private const string CONTENT_TYPE = 'application/ld+json';

    public function __construct(
        private HttpClientInterface $client,
        #[Autowire(env: 'APP_BROKER_BASE_URI')]
        private string $brokerUrl,
    ) {
    }

    /**
     * Upserts entities, in batches the broker accepts.
     *
     * A batch the broker accepts only in part answers 207, listing the
     * entities it rejected; the rest of the batch is written, so the upsert
     * carries on and reports the rejections instead of failing.
     *
     * @param list<array<string, mixed>> $entities
     */
    public function upsert(array $entities): UpsertResult
    {
        $status = Response::HTTP_NO_CONTENT;
        $partlyRejectedBodies = [];

        foreach (array_chunk($entities, self::BATCH_SIZE) as $batch) {
            $response = $this->client->request(
                'POST',
                rtrim($this->brokerUrl, '/').self::UPSERT_PATH,
                [
                    'headers' => ['Content-Type' => self::CONTENT_TYPE],
                    'json' => $batch,
                ]
            );

            $batchStatus = $response->getStatusCode();

            if ($batchStatus >= Response::HTTP_BAD_REQUEST) {
                throw new \RuntimeException(\sprintf('Broker rejected the upsert with HTTP %d: %s', $batchStatus, $response->getContent(false)));
            }

            if (Response::HTTP_MULTI_STATUS === $batchStatus) {
                $partlyRejectedBodies[] = $response->getContent(false);
            }

            if (Response::HTTP_MULTI_STATUS !== $status) {
                $status = $batchStatus;
            }
        }

        // Read the rejected entities out of each partly rejected batch.
        $rejectedPerBatch = array_map($this->rejections(...), $partlyRejectedBodies);

        // Combine the batches' rejections into one list.
        $rejected = array_merge(...$rejectedPerBatch);

        return new UpsertResult($status, $rejected);
    }

    /**
     * The entities an error 207 lists as rejected, with the reason the broker gave.
     *
     * @return array<string, Rejection>
     */
    private function rejections(string $body): array
    {
        $result = json_decode($body, true);
        $errors = \is_array($result) && \is_array($result['errors'] ?? null) ? $result['errors'] : [];

        $rejected = [];
        foreach ($errors as $error) {
            if (!\is_array($error) || !\is_string($error['entityId'] ?? null)) {
                continue;
            }

            $problem = \is_array($error['error'] ?? null) ? $error['error'] : [];
            $reason = $problem['detail'] ?? $problem['title'] ?? $problem['type'] ?? null;
            $rejected[$error['entityId']] = new Rejection(\is_string($reason) ? $reason : 'no reason given', $problem);
        }

        return $rejected;
    }

    /**
     * The ids of every entity of a type whose attribute equals a value.
     *
     * Used to find broker entities that are gone from upstream, in order to delete them.
     *
     * @return list<string>
     */
    public function idsWhereAttributeEquals(string $type, string $contextUrl, string $attribute, string $value): array
    {
        $ids = [];

        for ($offset = 0;; $offset += self::PAGE_SIZE) {
            $response = $this->client->request(
                'GET',
                rtrim($this->brokerUrl, '/').self::ENTITIES_PATH,
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Link' => \sprintf('<%s>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"', $contextUrl),
                    ],
                    'query' => [
                        'type' => $type,
                        'q' => \sprintf('%s=="%s"', $attribute, $value),
                        'attrs' => $attribute,
                        'limit' => self::PAGE_SIZE,
                        'offset' => $offset,
                    ],
                ]
            );

            $status = $response->getStatusCode();
            if ($status >= Response::HTTP_BAD_REQUEST) {
                throw new \RuntimeException(\sprintf('Broker refused to list %s entities with HTTP %d: %s', $type, $status, $response->getContent(false)));
            }

            $page = $response->toArray();
            foreach ($page as $entity) {
                $ids[] = (string) $entity['id'];
            }

            if (\count($page) < self::PAGE_SIZE) {
                return $ids;
            }
        }
    }

    /**
     * Deletes entities by id, in batches the broker accepts.
     *
     * @param list<string> $ids
     */
    public function delete(array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $response = $this->client->request(
                'POST',
                rtrim($this->brokerUrl, '/').self::BATCH_DELETE_PATH,
                ['json' => $batch]
            );

            // 207 lists the ids the broker could not delete; one that is
            // already gone is as good as deleted, so only a refusal of the
            // whole batch is an error.
            $status = $response->getStatusCode();
            if ($status >= Response::HTTP_BAD_REQUEST) {
                throw new \RuntimeException(\sprintf('Broker rejected the delete with HTTP %d: %s', $status, $response->getContent(false)));
            }
        }
    }

    public function brokerUrl(): string
    {
        return $this->brokerUrl;
    }
}
