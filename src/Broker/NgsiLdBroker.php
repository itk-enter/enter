<?php

declare(strict_types=1);

namespace App\Broker;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

    public function brokerUrl(): string
    {
        return $this->brokerUrl;
    }
}
