<?php

declare(strict_types=1);

namespace App\Test\Map;

use App\Broker\NgsiLdBroker;
use App\DataSetCatalog;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gives the data of the developer map: the test data sets that have entities
 * in the broker, and the entities of each test data set as GeoJSON features.
 */
#[When('dev')]
#[When('test')]
final readonly class MapDataSets
{
    /**
     * The prefix of the id of a test data set.
     */
    private const string TEST_PREFIX = 'test:';

    public function __construct(
        private DataSetCatalog $catalog,
        private NgsiLdBroker $broker,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Gets the test data sets that have entities in the broker.
     *
     * Each item has the keys of an item in /sources.json. It also has the key
     * features_url, the URL of the features of the data set.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $dataSets = [];

        foreach ($this->testDataSets() as $id => $dataSet) {
            if ($this->broker->count($id, $dataSet['context_url']) > 0) {
                $dataSets[] = [
                    ...$dataSet,
                    'features_url' => $this->urlGenerator->generate('test_features', ['id' => $id]),
                ];
            }
        }

        return $dataSets;
    }

    /**
     * Gets the entities of a test data set as GeoJSON features.
     *
     * @return \Generator<int, array<string, mixed>>|null the features, or null if no test data set has the id
     */
    public function features(string $id): ?\Generator
    {
        $dataSet = $this->testDataSets()[$id] ?? null;

        return null === $dataSet ? null : $this->broker->features($id, $dataSet['context_url']);
    }

    /**
     * Gets the test data sets from the list of data sets.
     *
     * @return array<string, array<string, mixed>> the data sets, by id
     */
    private function testDataSets(): array
    {
        $dataSets = [];

        foreach ($this->catalog->toArray() as $dataSet) {
            $id = $dataSet['id'] ?? null;

            if (\is_string($id) && str_starts_with($id, self::TEST_PREFIX)) {
                $dataSets[$id] = $dataSet;
            }
        }

        return $dataSets;
    }
}
