<?php

declare(strict_types=1);

namespace App\Tests\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Source\MtmSpatialMaps\OnStreetParking;
use App\Source\MtmSpatialMaps\ParkingSpot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the address that the sources of the parking register write.
 */
class HandicapParkingAddressTest extends TestCase
{
    /**
     * @param array<string, string>|null $address the expected value of the address, or null if there is no address
     */
    #[DataProvider('records')]
    public function testItWritesTheStreetAndTheHouseNumberAsTheAddress(int $bays, ?string $street, ?string $number, string $model, ?array $address): void
    {
        $payload = $this->entity($bays, $street, $number);

        $this->assertSame($model, $payload['type']);
        $this->assertSame($address, $payload['address']['value'] ?? null);
    }

    public function testItKeepsTheAddressAsTheName(): void
    {
        $payload = $this->entity(1, 'Heibergsgade', '27');

        $this->assertSame('Heibergsgade 27', $payload['name']['value']);
        $this->assertSame(['streetAddress' => 'Heibergsgade 27'], $payload['address']['value']);
    }

    /**
     * @return iterable<string, array{int, ?string, ?string, string, array<string, string>|null}>
     */
    public static function records(): iterable
    {
        yield 'one bay' => [1, 'Heibergsgade', '27', 'ParkingSpot', ['streetAddress' => 'Heibergsgade 27']];
        yield 'a site with bays' => [6, 'Domkirkeplads/Bispegade', '1', 'OnStreetParking', ['streetAddress' => 'Domkirkeplads/Bispegade 1']];
        yield 'no house number' => [1, 'Kystvejen', null, 'ParkingSpot', ['streetAddress' => 'Kystvejen']];
        yield 'no street and no house number' => [1, null, null, 'ParkingSpot', null];
    }

    /**
     * Maps one feed record with the source that supports it.
     *
     * @return array<string, mixed> the entity as a payload
     */
    private function entity(int $bays, ?string $street, ?string $number): array
    {
        $data = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [575000.0, 6225000.0]],
            'properties' => [
                'mi_prinx' => 1,
                'vejnavn' => $street,
                'husnnr' => $number,
                'invalidepladser' => $bays,
                'ident' => 'P',
            ],
        ];

        $transformer = new Wgs84Transformer();
        foreach ([new ParkingSpot(), new OnStreetParking()] as $source) {
            $entity = $source->createNgsiEntity($data, $transformer);
            if (null !== $entity) {
                return $entity->toPayload(['https://example.com/context.jsonld']);
            }
        }

        $this->fail('No source supports the record.');
    }
}
