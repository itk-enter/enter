<?php

declare(strict_types=1);

namespace App\Broker;

use App\SourceImporter\AbstractSourceImporter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads and writes entities in an NGSI-LD context broker.
 *
 * The class writes entities with the batch upsert operation. Thus a new
 * import updates the entities that it created before, and it does not fail on
 * 409 Conflict. This makes the import idempotent. This is important because
 * the importer makes the entity ids from the primary key of the source.
 */
final readonly class NgsiLdBroker
{
    private const string UPSERT_PATH = '/ngsi-ld/v1/entityOperations/upsert';

    private const string DELETE_PATH = '/ngsi-ld/v1/entityOperations/delete';

    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * The number of entities in each read request.
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
                Request::METHOD_POST,
                self::UPSERT_PATH,
                [
                    'headers' => ['Content-Type' => self::CONTENT_TYPE],
                    'json' => $batch,
                ]
            );

            $batchStatus = $response->getStatusCode();

            if ($batchStatus >= Response::HTTP_BAD_REQUEST) {
                throw new \RuntimeException(\sprintf('The broker rejected the upsert with HTTP %d: %s', $batchStatus, $response->getContent(false)));
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
                Request::METHOD_GET,
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
                throw new \RuntimeException(\sprintf('The broker refused to list %s entities with HTTP %d: %s', $type, $status, $response->getContent(false)));
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
     * Deletes entities by id in batches.
     *
     * @param list<string> $ids
     */
    public function delete(array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $response = $this->brokerClient->request(
                Request::METHOD_POST,
                rtrim($this->brokerUrl, '/').self::DELETE_PATH,
                ['json' => $batch]
            );

            $status = $response->getStatusCode();
            if ($status >= Response::HTTP_BAD_REQUEST) {
                throw new \RuntimeException(\sprintf('The broker rejected the delete with HTTP %d: %s', $status, $response->getContent(false)));
            }
        }
    }

    /**
     * Reads all the entities that a data set published, as GeoJSON features.
     *
     * The method finds the entities of all models by the source id that the
     * importer adds to each entity. The broker gives them in the simplified
     * format. The properties of each feature hold only the values, not the
     * NGSI-LD objects with a type and a value.
     * The Link header holds the context of the data set. Thus the broker
     * gives the attribute names that the source declared. The method reads
     * the next page until the broker gives no next page.
     *
     * The map sends the same request to show a data set.
     *
     * @param string $sourceId   the source id of the data set
     * @param string $contextUrl the JSON-LD context of the data set
     *
     * @return \Generator<int, array<string, mixed>> GeoJSON features, each with an id, a geometry and properties
     */
    public function features(string $sourceId, string $contextUrl): \Generator
    {
        $query = [
            'q' => \sprintf('%s=="%s"', AbstractSourceImporter::SOURCE_ID_ATTRIBUTE, $sourceId),
            'format' => 'simplified',
            'limit' => self::PAGE_SIZE,
        ];

        while (null !== $query) {
            $response = $this->brokerClient->request('GET', rtrim($this->brokerUrl, '/').self::ENTITIES_PATH, [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/geo+json',
                    'Link' => \sprintf('<%s>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"', $contextUrl),
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(\sprintf('The broker rejected the read of %s with HTTP %d: %s', $sourceId, $status, $response->getContent(false)));
            }

            $collection = $response->toArray();
            foreach (\is_array($collection['features'] ?? null) ? $collection['features'] : [] as $feature) {
                if (\is_array($feature)) {
                    yield $feature;
                }
            }

            $query = self::nextPage($response->getHeaders()['link'] ?? []);
        }
    }

    /**
     * Gets the query of the next page from the Link header. The broker sends
     * this header with a page that has a next page.
     *
     * The method uses only the query. The broker links to its own address, and
     * this app can use a different address to reach the broker.
     *
     * @param list<string> $links
     *
     * @return array<string, mixed>|null
     */
    private static function nextPage(array $links): ?array
    {
        foreach ($links as $link) {
            if (1 === preg_match('/<([^>]*)>[^,]*rel="next"/', $link, $match)) {
                parse_str((string) parse_url($match[1], \PHP_URL_QUERY), $query);

                return $query;
            }
        }

        return null;
    }

    public function brokerUrl(): string
    {
        return $this->brokerUrl;
    }
}
