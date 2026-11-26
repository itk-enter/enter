<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\FeedOrder;
use App\Source\MtmSpatialMaps\OnStreetParking;
use App\Source\MtmSpatialMaps\ParkingSpot;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\MtmSpatialMaps\ToiletOther;
use App\Source\SourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the sources of one feed come right after each other. The two
 * MTM handicap parking sources read the same feed.
 */
class FeedOrderTest extends TestCase
{
    public function testItPutsTheSourcesOfOneFeedTogether(): void
    {
        $sorted = FeedOrder::sort([new OnStreetParking(), new ToiletCity(), new ParkingSpot(), new ToiletOther()]);

        $this->assertSame([
            'mtm_spatialmaps-handicap-parking-on-street',
            'mtm_spatialmaps-handicap-parking-spot',
            'mtm_spatialmaps-toilet-city',
            'mtm_spatialmaps-toilet-other',
        ], array_map(static fn (SourceInterface $source): string => $source->definition->id, $sorted));
    }

    public function testItKeepsAnOrderWithoutSharedFeeds(): void
    {
        $sorted = FeedOrder::sort([new ToiletOther(), new ToiletCity()]);

        $this->assertSame(
            ['mtm_spatialmaps-toilet-other', 'mtm_spatialmaps-toilet-city'],
            array_map(static fn (SourceInterface $source): string => $source->definition->id, $sorted),
        );
    }

    public function testAnEmptyListStaysEmpty(): void
    {
        $this->assertSame([], FeedOrder::sort([]));
    }
}
