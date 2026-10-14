<?php

declare(strict_types=1);

namespace App\Broker;

use App\SourceImporter\AbstracSourceImporter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
     * @param list<array<string, mixed>> $entities
     *
     * @return int the broker's HTTP status code
     */
    public function upsert(array $entities): int
    {
        if ([] === $entities) {
            return 204;
        }

        $response = $this->client->request(
            'POST',
            rtrim($this->brokerUrl, '/').self::UPSERT_PATH,
            [
                'headers' => ['Content-Type' => self::CONTENT_TYPE],
                'json' => $entities,
            ]
        );

        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw new \RuntimeException(\sprintf('Broker rejected the upsert with HTTP %d: %s', $status, $response->getContent(false)));
        }

        return $status;
    }

    /**
     * Deletes entities by id, in one batch.
     *
     * @param list<string> $ids
     *
     * @return int the broker's HTTP status code
     */
    public function delete(array $ids): int
    {
        if ([] === $ids) {
            return 204;
        }

        $response = $this->client->request('POST', rtrim($this->brokerUrl, '/').self::DELETE_PATH, ['json' => $ids]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException(\sprintf('Broker rejected the delete with HTTP %d: %s', $status, $response->getContent(false)));
        }

        return $status;
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
            'q' => \sprintf('%s=="%s"', AbstracSourceImporter::SOURCE_ID_ATTRIBUTE, $sourceId),
            'format' => 'simplified',
            'limit' => self::PAGE_SIZE,
        ];

        while (null !== $query) {
            $response = $this->client->request('GET', rtrim($this->brokerUrl, '/').self::ENTITIES_PATH, [
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
