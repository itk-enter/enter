<?php

declare(strict_types=1);

namespace App\Tests;

use App\Conflation\Io\MergedDataSet;
use App\DataSetCatalog;
use App\Source\SourceInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests how the list of data sets on /sources groups the data sets.
 */
class DataSetCatalogTest extends KernelTestCase
{
    /**
     * The handicap parking merge reads the bays and the on-street sites, so
     * both are in the ParkingSpot group, after the merged data sets.
     */
    public function testASourceIsInTheGroupOfTheMergeThatReadsIt(): void
    {
        $groups = self::groupsByModel();

        $this->assertArrayNotHasKey('OnStreetParking', $groups);
        $this->assertSame(['merged-handicap-parking-spots', 'test:merged-handicap-parking-spots'], $groups['ParkingSpot']['merged']);
        $this->assertContains('osm-handicap-parking-spot', $groups['ParkingSpot']['sources']);
        $this->assertContains('osm-handicap-parking-on-street', $groups['ParkingSpot']['sources']);
        $this->assertContains('test:mtm_spatialmaps-handicap-parking-on-street', $groups['ParkingSpot']['sources']);
    }

    public function testASourceThatNoMergeReadsIsInTheGroupOfItsOwnModel(): void
    {
        $groups = self::groupsByModel();

        $this->assertSame([], $groups['OffStreetParking']['merged']);
        $this->assertSame(['osm-handicap-parking-off-street', 'test:osm-handicap-parking-off-street'], $groups['OffStreetParking']['sources']);
    }

    public function testEachGroupHasTheEnglishNameOfItsModelAsTitle(): void
    {
        $titles = array_column(static::getContainer()->get(DataSetCatalog::class)->all()['groups'], 'title', 'model');

        $this->assertSame('Parking spaces', $titles['ParkingSpot']);
        $this->assertSame('Public toilets', $titles['PublicToilet']);
    }

    public function testTheGroupsHoldEachDataSetOneTimeAndAreSortedByTitle(): void
    {
        $catalog = static::getContainer()->get(DataSetCatalog::class)->all();

        $models = array_column($catalog['groups'], 'model');
        $this->assertSame(array_values(array_unique($models)), $models);

        $titles = array_column($catalog['groups'], 'title');
        $sorted = $titles;
        sort($sorted);
        $this->assertSame($sorted, $titles);

        $grouped = array_merge(...array_map(static fn (array $group): array => [...$group['merged'], ...$group['sources']], array_values(self::groupsByModel())));
        $listed = [
            ...array_map(static fn (MergedDataSet $dataSet): string => $dataSet->id, $catalog['merged']),
            ...array_map(static fn (SourceInterface $source): string => $source->definition->id, $catalog['sources']),
        ];
        sort($grouped);
        sort($listed);
        $this->assertSame($listed, $grouped);
    }

    /**
     * The groups by model, with the ids of their data sets.
     *
     * @return array<string, array{merged: list<string>, sources: list<string>}>
     */
    private static function groupsByModel(): array
    {
        $groups = [];
        foreach (static::getContainer()->get(DataSetCatalog::class)->all()['groups'] as $group) {
            $groups[$group['model']] = [
                'merged' => array_map(static fn (MergedDataSet $dataSet): string => $dataSet->id, $group['merged']),
                'sources' => array_map(static fn (SourceInterface $source): string => $source->definition->id, $group['sources']),
            ];
        }

        return $groups;
    }
}
