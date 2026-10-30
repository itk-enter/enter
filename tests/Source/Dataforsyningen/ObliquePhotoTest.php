<?php

declare(strict_types=1);

namespace App\Tests\Source\Dataforsyningen;

use App\Geo\Wgs84Transformer;
use App\Source\Dataforsyningen\ObliquePhoto;
use PHPUnit\Framework\TestCase;

/**
 * Tests the mapping only. SkraafotoReader finds the photos.
 */
class ObliquePhotoTest extends TestCase
{
    private const array ITEM = [
        'entity' => 'urn:ngsi-ld:PublicToilet:merged-public-toilets-aarhus-toilet-city-123',
        'longitude' => 10.21,
        'latitude' => 56.15,
        'collection' => 'skraafotos2023',
        'photo' => '2023_83_29_2_0008_00004017',
        'direction' => 'east',
        'datetime' => '2023-04-20T10:00:00Z',
        'image' => 'https://enter.example/skraafoto/skraafotos2023/2023_83_29_2_0008_00004017.jpg',
    ];

    public function testItPutsThePhotoAtTheLocationOfTheMergedEntity(): void
    {
        $entity = $this->entity(self::ITEM);

        $this->assertSame('urn:ngsi-ld:ObliquePhoto:skraafoto-merged-public-toilets-aarhus-toilet-city-123', $entity['id']);
        $this->assertSame(['type' => 'Point', 'coordinates' => [10.21, 56.15]], $entity['location']['value']);
        $this->assertSame(self::ITEM['image'], $entity['image']['value']);
        $this->assertSame(self::ITEM['entity'], $entity['additionalInformation']['value']['entity']);
    }

    public function testItSkipsAnItemWithoutAPhoto(): void
    {
        $item = self::ITEM;
        unset($item['photo']);

        $this->assertNull(new ObliquePhoto()->createNgsiEntity($item, new Wgs84Transformer()));
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function entity(array $item): array
    {
        $entity = new ObliquePhoto()->createNgsiEntity($item, new Wgs84Transformer());
        $this->assertNotNull($entity);

        return $entity->toPayload(['https://example.com/context.jsonld']);
    }
}
