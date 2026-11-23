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
 * All the code in the app calls the broker through this class.
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

    private const string TYPES_PATH = '/ngsi-ld/v1/types';

    /**
     * The header that holds the number of entities when a query asks for the
     * count.
     */
    private const string RESULTS_COUNT_HEADER = 'ngsild-results-count';

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
            $response = $this->send(Request::METHOD_POST, self::UPSERT_PATH, [
                'headers' => ['Content-Type' => self::CONTENT_TYPE],
                'json' => $batch,
            ]);

            self::check($response, 'the upsert');

            $batchStatus = $response->getStatusCode();

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
        return $this->ids([
            'type' => $type,
            'q' => \sprintf('%s=="%s"', $attribute, $value),
            'attrs' => $attribute,
        ], $contextUrl, \sprintf('the list of %s entities', $type));
    }

    /**
     * Gets the IDs of all the entities of a type.
     *
     * The method sends no context. Thus the type must be the full URI, for
     * example "https://smartdatamodels.org/dataModel.Parking/OnStreetParking".
     * If the type is a short name, the broker gives no entities.
     *
     * @return list<string>
     */
    public function idsOfType(string $type): array
    {
        return $this->ids(['type' => $type], null, \sprintf('the list of %s entities', $type));
    }

    /**
     * Gets the types of all the entities in the broker.
     *
     * The method sends no context. Thus the broker gives each type as the
     * full URI, and the result can go to idsOfType().
     *
     * @return list<string>
     */
    public function types(): array
    {
        $response = $this->send(Request::METHOD_GET, self::TYPES_PATH, [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::check($response, 'the list of types');

        $types = $response->toArray()['typeList'] ?? [];

        return array_values(array_filter(\is_array($types) ? $types : [], \is_string(...)));
    }

    /**
     * Counts the entities that a data set published.
     *
     * The broker gives the number in the NGSILD-Results-Count header. The
     * request asks for one entity, because Scorpio gives a count of 0 if the
     * limit is 0.
     *
     * @param string $sourceId   the source id of the data set
     * @param string $contextUrl the JSON-LD context of the data set
     */
    public function count(string $sourceId, string $contextUrl): int
    {
        $response = $this->send(Request::METHOD_GET, self::ENTITIES_PATH, [
            'headers' => ['Accept' => 'application/json'],
            'query' => [
                'q' => self::sourceQuery($sourceId),
                'count' => 'true',
                'limit' => 1,
            ],
        ], $contextUrl);

        self::check($response, \sprintf('the count of %s', $sourceId));

        $count = $response->getHeaders()[self::RESULTS_COUNT_HEADER][0] ?? '';
        if (!ctype_digit($count)) {
            throw new \RuntimeException(\sprintf('The broker gave no count of %s', $sourceId));
        }

        return (int) $count;
    }

    /**
     * Deletes entities by id in batches.
     *
     * @param list<string> $ids
     */
    public function delete(array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $response = $this->send(Request::METHOD_POST, self::DELETE_PATH, ['json' => $batch]);

            self::check($response, 'the delete');
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
     * @param string $sourceId   the source id of the data set
     * @param string $contextUrl the JSON-LD context of the data set
     *
     * @return \Generator<int, array<string, mixed>> GeoJSON features, each with an id, a geometry and properties
     */
    public function features(string $sourceId, string $contextUrl): \Generator
    {
        $query = [
            'q' => self::sourceQuery($sourceId),
            'format' => 'simplified',
            'limit' => self::PAGE_SIZE,
        ];

        while (null !== $query) {
            $response = $this->send(Request::METHOD_GET, self::ENTITIES_PATH, [
                'query' => $query,
                'headers' => ['Accept' => 'application/geo+json'],
            ], $contextUrl);

            self::check($response, \sprintf('the read of %s', $sourceId));

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
     * Reads some attributes of all the entities that a data set published, in
     * normalized form.
     *
     * The normalized form keeps the metadata of each attribute, for example
     * its observedAt. The broker gives only the entities that have one or
     * more of the attributes.
     *
     * @param string       $sourceId   the source id of the data set
     * @param string       $contextUrl the JSON-LD context of the data set
     * @param list<string> $attributes the attributes to read
     *
     * @return \Generator<string, array<string, mixed>> the entities in normalized form, by id
     */
    public function entities(string $sourceId, string $contextUrl, array $attributes): \Generator
    {
        for ($offset = 0;; $offset += self::PAGE_SIZE) {
            $response = $this->send(Request::METHOD_GET, self::ENTITIES_PATH, [
                'headers' => ['Accept' => 'application/json'],
                'query' => [
                    'q' => self::sourceQuery($sourceId),
                    'attrs' => implode(',', $attributes),
                    'limit' => self::PAGE_SIZE,
                    'offset' => $offset,
                ],
            ], $contextUrl);

            self::check($response, \sprintf('the read of %s', $sourceId));

            $page = $response->toArray();
            foreach ($page as $entity) {
                if (\is_array($entity) && \is_string($entity['id'] ?? null)) {
                    yield $entity['id'] => $entity;
                }
            }

            if (\count($page) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * Reads some attributes of one entity, in normalized form.
     *
     * @param list<string> $attributes the attributes to read
     *
     * @return array<string, mixed>|null the entity, or null if it does not exist
     */
    public function entity(string $id, string $contextUrl, array $attributes): ?array
    {
        $response = $this->send(Request::METHOD_GET, self::ENTITIES_PATH.'/'.rawurlencode($id), [
            'headers' => ['Accept' => 'application/json'],
            'query' => ['attrs' => implode(',', $attributes)],
        ], $contextUrl);

        if (Response::HTTP_NOT_FOUND === $response->getStatusCode()) {
            return null;
        }

        self::check($response, \sprintf('the read of %s', $id));

        return $response->toArray();
    }

    /**
     * Adds or replaces some attributes of one entity, and keeps the others.
     *
     * The method uses the NGSI-LD operation "Append Entity Attributes". It
     * writes only the attributes that it gets, so it does not change values
     * that another process wrote at the same time.
     *
     * @param array<string, array<string, mixed>> $attributes the attributes in normalized form, by name
     *
     * @return bool false if the entity does not exist
     */
    public function appendAttributes(string $id, string $contextUrl, array $attributes): bool
    {
        $response = $this->send(Request::METHOD_POST, self::ENTITIES_PATH.'/'.rawurlencode($id).'/attrs', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $attributes,
        ], $contextUrl);

        $status = $response->getStatusCode();
        if (Response::HTTP_NOT_FOUND === $status) {
            return false;
        }

        if (Response::HTTP_MULTI_STATUS === $status) {
            throw self::rejected($response, \sprintf('the update of %s', $id));
        }

        self::check($response, \sprintf('the update of %s', $id));

        return true;
    }

    public function brokerUrl(): string
    {
        return $this->brokerUrl;
    }

    /**
     * Reads the IDs of the entities that a query selects, page by page.
     *
     * @param array<string, string> $query      the query without limit and offset
     * @param string|null           $contextUrl the context for the names in the query, or null for no context
     * @param string                $action     the request, for the message if the broker rejects it
     *
     * @return list<string>
     */
    private function ids(array $query, ?string $contextUrl, string $action): array
    {
        $pages = [];

        for ($offset = 0;; $offset += self::PAGE_SIZE) {
            $response = $this->send(Request::METHOD_GET, self::ENTITIES_PATH, [
                'headers' => ['Accept' => 'application/json'],
                'query' => [...$query, 'limit' => self::PAGE_SIZE, 'offset' => $offset],
            ], $contextUrl);

            self::check($response, $action);

            $page = $response->toArray();
            $pages[] = $page;

            if (\count($page) < self::PAGE_SIZE) {
                break;
            }
        }

        return array_column(array_merge(...$pages), 'id');
    }

    /**
     * Sends a request to the broker.
     *
     * If the method gets a context URL, it adds the Link header with that
     * context. Then the broker uses the attribute and type names of the
     * context.
     *
     * @param string               $path       the path of the NGSI-LD API, for example "/ngsi-ld/v1/entities"
     * @param array<string, mixed> $options    the options of the HTTP client
     * @param string|null          $contextUrl the JSON-LD context, or null for no context
     */
    private function send(string $method, string $path, array $options, ?string $contextUrl = null): ResponseInterface
    {
        if (null !== $contextUrl) {
            $options['headers']['Link'] = \sprintf('<%s>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"', $contextUrl);
        }

        return $this->brokerClient->request($method, rtrim($this->brokerUrl, '/').$path, $options);
    }

    /**
     * Throws an exception if the broker rejected the request.
     *
     * @param string $action the request, for example "the upsert"
     */
    private static function check(ResponseInterface $response, string $action): void
    {
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw self::rejected($response, $action);
        }
    }

    private static function rejected(ResponseInterface $response, string $action): \RuntimeException
    {
        return new \RuntimeException(\sprintf('The broker rejected %s with HTTP %d: %s', $action, $response->getStatusCode(), $response->getContent(false)));
    }

    /**
     * Gets the query that selects the entities of a data set by the source id
     * that the importer adds to each entity.
     */
    private static function sourceQuery(string $sourceId): string
    {
        return \sprintf('%s=="%s"', AbstractSourceImporter::SOURCE_ID_ATTRIBUTE, $sourceId);
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
}
