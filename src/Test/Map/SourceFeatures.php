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
 * The entities of one source, as plain GeoJSON.
 */
final readonly class SourceFeatures
{
    private const string ENTITIES_PATH = '/ngsi-ld/v1/entities';

    /**
     * The attribute every source stamps its access URL onto, and so what
     * tells its entities from those of another source publishing the same
     * model.
     */
    private const string SOURCE_ATTRIBUTE = 'source';

    public function __construct(
        private BrokerReader $reader,
    ) {
    }

    /**
     * The model and the stamp are asked for as the source declared them. Sent
     * along with the source's own context, the broker expands both exactly as
     * it did on publication, and compacts its answer back to the same terms.
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
     * The context a request is read under, in the form NGSI-LD takes it: a
     * JSON-LD context link.
     */
    private function contextLink(Definition $definition): string
    {
        return \sprintf(
            '<%s>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"',
            $definition->contextUrl,
        );
    }

    /**
     * The attributes free of the Property wrapper, with the entity's own id
     * among them.
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

            $properties[$name] = \is_array($value) && isset($value['value'])
                ? $value['value']
                : $value;
        }

        return $properties;
    }
}
