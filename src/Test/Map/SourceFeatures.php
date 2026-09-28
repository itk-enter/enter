<?php

declare(strict_types=1);

namespace App\Test\Map;

use App\Broker\BrokerReader;
use App\Source\Definition;
use App\Source\SourceInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Reads what one source has in the broker and turns it into plain GeoJSON.
 *
 * The broker answers in NGSI-LD, where every attribute is wrapped in a
 * Property object. The map wants one feature per entity with bare values
 * under readable names, and that is what this produces.
 */
final readonly class SourceFeatures
{
    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * All sources publish into the same model, so the broker cannot tell
     * their entities apart by type. Every source therefore stamps its access
     * URL onto this attribute when it publishes, and the query filters on it.
     */
    private const string SOURCE_ATTRIBUTE = 'source';

    public function __construct(
        private BrokerReader $reader,
    ) {
    }

    /**
     * Fetches the source's entities from the broker and converts them to a
     * GeoJSON FeatureCollection.
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     *
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function forSource(SourceInterface $source): array
    {
        $definition = $source->definition;

        $collection = $this->reader->readAll(
            self::ENTITIES_PATH,
            [
                'type' => $definition->model,
                'q' => \sprintf('%s=="%s"', self::SOURCE_ATTRIBUTE, $definition->accessUrlBase()),
            ],
            [
                'accept' => 'application/geo+json',
                'link' => $this->contextLink($definition),
            ],
        );

        $features = [];
        foreach ($collection['features'] ?? [] as $feature) {
            $features[] = [
                'type' => 'Feature',
                'geometry' => $feature['geometry'] ?? null,
                'properties' => ['dataset' => $definition->id] + $this->flatten($feature),
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * The Link header that tells the broker which JSON-LD context to read
     * the request under.
     */
    private function contextLink(Definition $definition): string
    {
        return \sprintf(
            '<%s>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"',
            $definition->contextUrl,
        );
    }

    /**
     * Turns an entity's NGSI-LD attributes into plain GeoJSON properties.
     *
     * Each attribute arrives as {"type": "Property", "value": ...} and only
     * the value is kept.
     *
     * @param array<string, mixed> $feature
     *
     * @return array<string, mixed>
     */
    private function flatten(array $feature): array
    {
        $properties = ['id' => $feature['id'] ?? null];

        foreach ($feature['properties'] ?? [] as $name => $value) {
            if ('type' === $name || 'location' === $name) {
                continue;
            }

            $properties[$name] = \is_array($value) && isset($value['value'])
                ? $value['value']
                : $value;
        }

        return $properties;
    }
}
