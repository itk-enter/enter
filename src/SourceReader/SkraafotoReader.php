<?php

declare(strict_types=1);

namespace App\SourceReader;

use App\Broker\NgsiLdBroker;
use App\Conflation\ConflationManager;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\GeometryHelper;
use App\Skraafoto\SkraafotoClient;
use App\Source\SourceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Finds one oblique photo for each merged entity that a merge wants a photo
 * for.
 *
 * A merge wants a photo if it names the model of the source as an augmenting
 * model. The reader reads the merged entities of that merge from the broker.
 * For each merged entity, it finds the newest oblique photo of the location.
 * Thus the source holds only the photos that a merge uses.
 *
 * A merged entity gets its photo on the next run of the merge. Thus, to add
 * the photos, run the merge, import the source and run the merge again.
 *
 * Each item that the reader gives has these keys:
 *
 * - entity: the id of the merged entity.
 * - longitude and latitude: the location of the merged entity.
 * - collection, photo, direction and datetime: the photo, from its STAC item.
 * - image: the address of the thumbnail on this application. The address
 *   does not contain the token.
 */
final readonly class SkraafotoReader implements SourceReaderInterface
{
    public function __construct(
        private SkraafotoClient $client,
        private ConflationManager $conflations,
        private MergedDataSetFactory $dataSets,
        private NgsiLdBroker $broker,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function read(SourceInterface $source): array
    {
        $definition = $source->definition;
        $test = str_starts_with($definition->id, ConflationFetcher::TEST_PREFIX);
        $collection = $this->client->newestCollection();

        $items = [];
        foreach ($this->conflations->getConflations() as $conflation) {
            // A test source serves only test merges, and a published source only published merges.
            if (!$conflation->isAugmenting($definition->model) || str_starts_with($conflation->id, ConflationFetcher::TEST_PREFIX) !== $test) {
                continue;
            }

            $dataSet = $this->dataSets->create($conflation);
            foreach ($this->broker->features($dataSet->id, $dataSet->contextUrl) as $feature) {
                $item = $this->item($feature, $collection);
                if (null !== $item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $feature
     *
     * @return array<string, mixed>|null
     */
    private function item(array $feature, string $collection): ?array
    {
        $id = $feature['id'] ?? null;
        $geometry = $feature['geometry'] ?? null;
        if (!\is_string($id) || !\is_array($geometry)) {
            return null;
        }

        if ('Point' === ($geometry['type'] ?? null) && \is_array($geometry['coordinates'] ?? null)) {
            [$longitude, $latitude] = $geometry['coordinates'];
        } else {
            [$longitude, $latitude] = new GeometryHelper()->getCentroid($geometry)->toArray();
        }

        $photo = $this->client->photoAt($collection, (float) $longitude, (float) $latitude);
        $photoId = $photo['id'] ?? null;
        if (!\is_string($photoId)) {
            return null;
        }

        return [
            'entity' => $id,
            'longitude' => (float) $longitude,
            'latitude' => (float) $latitude,
            'collection' => $collection,
            'photo' => $photoId,
            'direction' => $photo['properties']['direction'] ?? null,
            'datetime' => $photo['properties']['datetime'] ?? null,
            'image' => $this->urlGenerator->generate(
                'app_skraafoto_thumbnail',
                ['collection' => $collection, 'photo' => $photoId],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }
}
