<?php

declare(strict_types=1);

namespace App\Test\Map;

use App\Broker\BrokerReader;
use App\Source\SourceInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The entities of one source, as plain GeoJSON.
 */
final readonly class SourceFeatures
{
    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * The attribute every source stamps its access URL onto, and so the only
     * thing in the payload that says which data set an entity came from.
     */
    private const string SOURCE_ATTRIBUTE = 'https://smartdatamodels.org/source';

    public function __construct(
        private BrokerReader $reader,
    ) {
    }

    /**
     * Every source publishes into the same model, so the broker cannot be
     * asked for one data set at a time: what separates them is an attribute
     * it expanded against a default vocabulary and can no longer be queried
     * on. The whole model is read and the source's own picked out here.
     *
     * @param SourceInterface $source
     * @param string $type the expanded entity type
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function forSource(SourceInterface $source, string $type): array
    {
        $collection = $this->reader->readAll(
            self::ENTITIES_PATH,
            ['type' => $type],
            ['accept' => 'application/geo+json'],
        );

        $own = $source->definition->accessUrlBase();

        $features = [];
        foreach ($collection['features'] ?? [] as $feature) {
            if ($own !== $this->sourceOf($feature)) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $feature['geometry'] ?? null,
                'properties' => ['dataset' => $source->definition->id] + $this->flatten($feature),
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @param array<string, mixed> $feature
     */
    private function sourceOf(array $feature): ?string
    {
        $source = $feature['properties'][self::SOURCE_ATTRIBUTE] ?? null;

        return \is_array($source) ? ($source['value'] ?? null) : $source;
    }

    /**
     * The attributes under their short names, free of the Property wrapper,
     * with the entity's own id among them.
     *
     * @param array<string, mixed> $feature
     *
     * @return array<string, mixed>
     */
    private function flatten(array $feature): array
    {
        $properties = ['id' => $feature['id'] ?? null];

        foreach ($feature['properties'] ?? [] as $name => $value) {
            // The entity type repeats what was asked for, and the geometry is
            // carried by the feature itself.
            if ('type' === $name || 'location' === $name) {
                continue;
            }

            $properties[$this->shortName($name)] = \is_array($value) && isset($value['value'])
                ? $value['value']
                : $value;
        }

        return $properties;
    }

    /**
     * The last segment of an expanded attribute name, which is the term the
     * source declared before the broker expanded it.
     */
    private function shortName(string $name): string
    {
        $position = strrpos($name, '/');

        return false === $position ? $name : substr($name, $position + 1);
    }
}
