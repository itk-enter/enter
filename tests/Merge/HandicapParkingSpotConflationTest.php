<?php

declare(strict_types=1);

namespace App\Tests\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationManager;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Pipeline\ConflictResolver;
use App\Merge\HandicapParkingSpotConflation;
use App\Source\SourceInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class HandicapParkingSpotConflationTest extends KernelTestCase
{
    /**
     * The source ids of the data sets a merge reads.
     *
     * @return list<string>
     */
    private static function dataSets(string $merge): array
    {
        $container = static::getContainer();
        $conflation = $container->get(ConflationManager::class)->getConflation($merge);

        return array_map(static fn (SourceInterface $source): string => $source->definition->id, $container->get(ConflationFetcher::class)->dataSets($conflation));
    }

    /**
     * One bay as stated by the given data sets. A record from a site data
     * set is a bay restated from that site.
     *
     * @param array<string, array{float, float}> $points       by source id
     * @param array<string, string>              $descriptions by source id
     */
    private static function resolve(array $points, array $descriptions = []): mixed
    {
        $records = array_map(
            static fn (string $sourceId, array $point): Record => new Record(
                $sourceId.'-1',
                $sourceId,
                $point[0],
                $point[1],
                'ParkingSpot',
                isset($descriptions[$sourceId]) ? ['description' => $descriptions[$sourceId]] : [],
                null,
                str_ends_with($sourceId, '-on-street') ? $sourceId.'-site' : null,
            ),
            array_keys($points),
            $points,
        );

        return new ConflictResolver(Conflation::of(HandicapParkingSpotConflation::class)->conflictResolution)->resolve(new Cluster($records));
    }

    public function testItReadsEveryPublishedBayAndOnStreetSiteDataSet(): void
    {
        $this->assertSame([
            'mtm_spatialmaps-handicap-parking-on-street',
            'mtm_spatialmaps-handicap-parking-spot',
            'osm-handicap-parking-on-street',
            'osm-handicap-parking-spot',
        ], self::dataSets('merged-handicap-parking-spots'));
    }

    public function testItsTestMergeReadsEveryTestBayAndOnStreetSiteDataSet(): void
    {
        $this->assertSame([
            'test:mtm_spatialmaps-handicap-parking-on-street',
            'test:mtm_spatialmaps-handicap-parking-spot',
            'test:osm-handicap-parking-on-street',
            'test:osm-handicap-parking-spot',
        ], self::dataSets('test:merged-handicap-parking-spots'));
    }

    public function testTheLocationOfAMappedBayWinsOverTheSites(): void
    {
        // The two sites agree, so a majority would pick them.
        $entity = self::resolve([
            'mtm_spatialmaps-handicap-parking-spot' => [10.2101, 56.15],
            'mtm_spatialmaps-handicap-parking-on-street' => [10.2102, 56.15],
            'osm-handicap-parking-on-street' => [10.2102, 56.15],
        ]);

        $this->assertSame(['type' => 'Point', 'coordinates' => [10.2101, 56.15]], $entity->location);
    }

    public function testTheLocationOfAnOsmBayWinsOverAMunicipalityBay(): void
    {
        $entity = self::resolve([
            'mtm_spatialmaps-handicap-parking-spot' => [10.2101, 56.15],
            'osm-handicap-parking-spot' => [10.2103, 56.15],
        ]);

        $this->assertSame(['type' => 'Point', 'coordinates' => [10.2103, 56.15]], $entity->location);
    }

    public function testWithoutAMappedBayTheMunicipalitySiteGivesTheLocation(): void
    {
        $entity = self::resolve([
            'mtm_spatialmaps-handicap-parking-on-street' => [10.2101, 56.15],
            'osm-handicap-parking-on-street' => [10.2102, 56.15],
        ]);

        $this->assertSame(['type' => 'Point', 'coordinates' => [10.2101, 56.15]], $entity->location);
    }

    public function testTheMajorityDecidesOtherAttributesFirst(): void
    {
        $entity = self::resolve(
            [
                'mtm_spatialmaps-handicap-parking-spot' => [10.21, 56.15],
                'osm-handicap-parking-spot' => [10.21, 56.15],
                'osm-handicap-parking-on-street' => [10.21, 56.15],
            ],
            [
                'mtm_spatialmaps-handicap-parking-spot' => 'Ved indgangen',
                'osm-handicap-parking-spot' => 'Bag huset',
                'osm-handicap-parking-on-street' => 'Bag huset',
            ],
        );

        $this->assertSame('Bag huset', $entity->attributes['description'] ?? null);
    }

    public function testWithoutAMajorityTheMunicipalityBayDecides(): void
    {
        $entity = self::resolve(
            [
                'mtm_spatialmaps-handicap-parking-spot' => [10.21, 56.15],
                'osm-handicap-parking-spot' => [10.21, 56.15],
            ],
            [
                'mtm_spatialmaps-handicap-parking-spot' => 'Ved indgangen',
                'osm-handicap-parking-spot' => 'Bag huset',
            ],
        );

        $this->assertSame('Ved indgangen', $entity->attributes['description'] ?? null);
    }

    public function testTheProvenanceNamesTheSiteOfARestatedBay(): void
    {
        $entity = self::resolve([
            'osm-handicap-parking-spot' => [10.21, 56.15],
            'osm-handicap-parking-on-street' => [10.21, 56.15],
        ]);

        $this->assertSame(['osm-handicap-parking-on-street-site', 'osm-handicap-parking-spot-1'], $entity->provenance);
    }
}
