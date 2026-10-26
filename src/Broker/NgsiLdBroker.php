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
 * Writing uses the batch upsert operation so a re-import updates the entities
 * it already created instead of failing on 409 Conflict. That makes the whole
 * import idempotent, which matters because the entity ids are derived from
 * the source's own primary key.
 */
final readonly class NgsiLdBroker
{
    private const string UPSERT_PATH = '/ngsi-ld/v1/entityOperations/upsert';

    private const string DELETE_PATH = '/ngsi-ld/v1/entityOperations/delete';

    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * Entities asked for per request when reading.
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
                throw new \RuntimeException(\sprintf('Broker rejected the delete with HTTP %d: %s', $status, $response->getContent(false)));
            }
        }
    }

    /**
     * Reads every entity a data set has published, as GeoJSON features.
     *
     * The entities are found by the source id the importer stamps on each of
     * them, whatever their model, and come in the simplified format: each
     * feature's properties hold bare values, such as "name": "Park toilet",
     * rather than NGSI-LD's {"type": "Property", "value": …}. The data set's
     * context goes in the Link header, so the broker answers with the
     * attribute names the source declared. The pages are followed until the
     * broker gives no next one.
     *
     * This is the request the map makes to show a data set.
     *
     * @param string $sourceId   the data set's source id, such as "osm-public-toilet"
     * @param string $contextUrl the data set's JSON-LD context
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
                throw new \RuntimeException(\sprintf('Broker rejected reading %s with HTTP %d: %s', $sourceId, $status, $response->getContent(false)));
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
     * The query of the next page, from the Link header the broker sends with
     * a page that has one.
     *
     * Only the query is taken: the broker links with the address it knows
     * itself by, which need not be the one it is reached at from here.
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
