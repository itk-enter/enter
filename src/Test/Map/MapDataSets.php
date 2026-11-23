<?php

declare(strict_types=1);

namespace App\Test\Map;

use App\Broker\NgsiLdBroker;
use App\Conflation\Io\ConflationFetcher;
use App\DataSetCatalog;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gives the data of the developer map. The map shows only the entities of the
 * model of each data set.
 */
#[When('dev')]
#[When('test')]
final readonly class MapDataSets
{
    public function __construct(
        private DataSetCatalog $catalog,
        private NgsiLdBroker $broker,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Gets the test data sets that have entities in the broker, as items of
     * /sources.json with the key features_url.
     *
     * A data set that the broker cannot count stays in the list, so that the
     * map shows the error when it reads the features.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $testDataSets = $this->testDataSets();

        $queries = [];
        foreach ($testDataSets as $id => $dataSet) {
            $queries[$id] = ['contextUrl' => $dataSet['context_url'], 'type' => $dataSet['model']];
        }

        $counts = $this->broker->counts($queries);

        $dataSets = [];
        foreach ($testDataSets as $id => $dataSet) {
            if (0 !== $counts[$id]) {
                $dataSets[] = [
                    ...$dataSet,
                    'features_url' => $this->urlGenerator->generate('test_features', ['id' => $id]),
                ];
            }
        }

        return $dataSets;
    }

    /**
     * Gets the entities of a test data set as GeoJSON features. The method
     * reads all the pages before it returns, so that a broker error gives an
     * error response.
     *
     * @return list<array<string, mixed>>|null the features, or null if no test data set has the id
     */
    public function features(string $id): ?array
    {
        $dataSet = str_starts_with($id, ConflationFetcher::TEST_PREFIX) ? $this->catalog->find($id) : null;

        return null === $dataSet ? null : iterator_to_array($this->broker->features($id, $dataSet['context_url'], $dataSet['model']), false);
    }

    /**
     * @return array<string, array<string, mixed>> the test data sets, by id
     */
    private function testDataSets(): array
    {
        $dataSets = [];

        foreach ($this->catalog->toArray() as $dataSet) {
            $id = $dataSet['id'] ?? null;

            if (\is_string($id) && str_starts_with($id, ConflationFetcher::TEST_PREFIX)) {
                $dataSets[$id] = $dataSet;
            }
        }

        return $dataSets;
    }
}
