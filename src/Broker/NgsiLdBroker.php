<?php

declare(strict_types=1);

namespace App\Broker;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Writes entities to an NGSI-LD context broker.
 */
final readonly class NgsiLdBroker
{
    private const string UPSERT_PATH = '/ngsi-ld/v1/entityOperations/upsert';
    private const string BATCH_DELETE_PATH = '/ngsi-ld/v1/entityOperations/delete';
    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * The maximum number of entities in one read.
     */
    private const int PAGE_SIZE = 1000;

    /**
     * The maximum number of entities in one batch operation.
     */
    private const int BATCH_SIZE = 1000;

    private const string CONTENT_TYPE = 'application/ld+json';

    public function __construct(
        private HttpClientInterface $brokerClient,
        #[Autowire(env: 'APP_BROKER_BASE_URI')]
        private string $brokerUrl,
    ) {
    }

    /**
     * Upserts entities in batches.
     *
     * @param list<array<string, mixed>> $entities
     */
    public function upsert(array $entities): UpsertResult
    {
        $status = Response::HTTP_NO_CONTENT;
        $partlyRejectedResponses = [];

        foreach (array_chunk($entities, self::BATCH_SIZE) as $batch) {
            $response = $this->brokerClient->request(
                'POST',
                self::UPSERT_PATH,
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
                $partlyRejectedResponses[] = $response;
            }

            if (Response::HTTP_MULTI_STATUS !== $status) {
                $status = $batchStatus;
            }
        }

        $rejectedPerBatch = array_map($this->rejections(...), $partlyRejectedResponses);

        $rejected = array_merge(...$rejectedPerBatch);

        return new UpsertResult($status, $rejected);
    }

    /**
     * Gets the rejected entities from a 207 response.
     *
     * @see https://cim.etsi.org/NGSI-LD/official/clause-5.html#5.2.16
     *
     * @return array<string, Rejection>
     */
    private function rejections(ResponseInterface $response): array
    {
        $result = $response->toArray(false);
        $errors = \is_array($result['errors'] ?? null) ? $result['errors'] : [];

        $rejected = [];
        foreach ($errors as $error) {
            if (!\is_array($error) || !\is_string($error['entityId'] ?? null)) {
                continue;
            }

            $problem = \is_array($error['error'] ?? null) ? $error['error'] : [];
            $rejected[$error['entityId']] = new Rejection($this->reason($problem), $problem);
        }

        return $rejected;
    }

    /**
     * Gets the first text member of a problem details object.
     *
     * @param array<mixed> $problem
     */
    private function reason(array $problem): string
    {
        foreach (['detail', 'title', 'type'] as $member) {
            if (\is_string($problem[$member] ?? null) && '' !== $problem[$member]) {
                return $problem[$member];
            }
        }

        return 'no reason given';
    }

    /**
     * Gets the IDs of the entities of a type with an attribute value.
     *
     * @return list<string>
     */
    public function fetchIdsWhereAttributeEquals(string $type, string $contextUrl, string $attribute, string $value): array
    {
        $pages = [];

        for ($offset = 0;; $offset += self::PAGE_SIZE) {
            $response = $this->brokerClient->request(
                'GET',
                self::ENTITIES_PATH,
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
            $pages[] = $page;

            if (\count($page) < self::PAGE_SIZE) {
                break;
            }
        }

        return array_column(array_merge(...$pages), 'id');
    }

    /**
     * Deletes entities by ID in batches.
     *
     * @param list<string> $ids
     */
    public function delete(array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $response = $this->brokerClient->request(
                'POST',
                self::BATCH_DELETE_PATH,
                ['json' => $batch]
            );

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
