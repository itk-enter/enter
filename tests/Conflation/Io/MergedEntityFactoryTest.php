<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Io;

use App\Conflation\Conflation;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\StatedValue;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use PHPUnit\Framework\TestCase;

class MergedEntityFactoryTest extends TestCase
{
    private const string CITY = 'urn:ngsi-ld:PublicToilet:aarhus-toilet-city-123';
    private const string OSM = 'urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17';

    private static function conflation(): Conflation
    {
        return new Conflation(
            id: 'merged-public-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(MergedEntity $entity): array
    {
        return new MergedEntityFactory()->create(self::conflation(), $entity)->toPayload(['https://example.org/context.jsonld']);
    }

    public function testItBuildsTheEntityInTheResultModel(): void
    {
        $payload = self::payload(new MergedEntity(
            self::CITY,
            ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
            ['name' => 'Park toilet', 'fee' => false],
            [self::CITY, self::OSM],
        ));

        $this->assertSame('urn:ngsi-ld:PublicToilet:merged-public-toilets-aarhus-toilet-city-123', $payload['id']);
        $this->assertSame('PublicToilet', $payload['type']);
        $this->assertSame(['type' => 'Property', 'value' => 'Park toilet'], $payload['name']);
        $this->assertSame(['type' => 'Property', 'value' => false], $payload['fee']);
        $this->assertSame(['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]]], $payload['location']);
        $this->assertSame(['type' => 'Property', 'value' => 'merged-public-toilets'], $payload['sourceId']);
        // Provenance: every input entity, as the model's seeAlso.
        $this->assertSame(['type' => 'Property', 'value' => [self::CITY, self::OSM]], $payload['seeAlso']);
        // Nothing to put in additionalInformation, so it is left out.
        $this->assertArrayNotHasKey('additionalInformation', $payload);
    }

    public function testAdditionalInformationIsPutBackTogetherWithTheConflicts(): void
    {
        $payload = self::payload(new MergedEntity(
            self::CITY,
            ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
            ['additionalInformation.check_date' => '2025-06-01', 'additionalInformation.operator' => 'Aarhus Kommune', 'name' => 'Park toilet'],
            [self::CITY, self::OSM],
            ['openingHours' => [new StatedValue('08–20', self::CITY, 'mtm_spatialmaps-toilet-city'), new StatedValue('06–22', self::OSM, 'osm-public-toilet')]],
        ));

        $this->assertSame(['type' => 'Property', 'value' => [
            'check_date' => '2025-06-01',
            'conflicts' => ['openingHours' => [
                ['value' => '08–20', 'dataSet' => 'mtm_spatialmaps-toilet-city', 'entity' => self::CITY],
                ['value' => '06–22', 'dataSet' => 'osm-public-toilet', 'entity' => self::OSM],
            ]],
            'operator' => 'Aarhus Kommune',
        ]], $payload['additionalInformation']);
        $this->assertArrayNotHasKey('openingHours', $payload);
        $this->assertArrayNotHasKey('additionalInformation.operator', $payload);
    }

    public function testAMemberWithoutAnNgsiLdIdGivesItsWholeId(): void
    {
        $this->assertSame('urn:ngsi-ld:PublicToilet:merged-public-toilets-A-17', MergedEntityFactory::id(self::conflation(), 'A-17'));
    }
}
