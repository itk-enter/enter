<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\ConflationFetcher;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\MergedDataSetFactory;
use App\Source\FindToilet\PublicToilet as FindToiletPublicToilet;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\Source\SourceInterface;
use App\SourceManager;
use App\Tests\Conflation\Fixtures\ToiletOfAnotherVersion;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class MergedDataSetFactoryTest extends TestCase
{
    private const string CONTEXT = 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld';

    /**
     * @param list<SourceInterface> $sources
     */
    private static function factory(array $sources): MergedDataSetFactory
    {
        return new MergedDataSetFactory(new ConflationFetcher(new SourceManager($sources), new NgsiLdBroker(new MockHttpClient(), 'http://broker.example'), new NullLogger()));
    }

    private static function conflation(?string $contextUrl = null, ?string $licence = null): Conflation
    {
        return new Conflation(
            id: 'merged-public-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
            contextUrl: $contextUrl,
            licence: $licence,
        );
    }

    public function testItDescribesTheMergedDataSet(): void
    {
        $dataSet = self::factory([new OsmPublicToilet(), new FindToiletPublicToilet()])->create(self::conflation());

        $this->assertSame('merged-public-toilets', $dataSet->id);
        $this->assertSame('Merged toilets', $dataSet->title);
        $this->assertSame('PublicToilet', $dataSet->model);
        $this->assertSame(['findtoilet-public-toilet', 'osm-public-toilet'], $dataSet->inputs);
        // The context the toilet data sets declare.
        $this->assertSame(self::CONTEXT, $dataSet->contextUrl);
        // OSM is ODbL and FindToilet states no licence, so it is unsettled.
        $this->assertNull($dataSet->licence);
    }

    public function testInputsSharingALicenceGiveItToTheMerge(): void
    {
        $dataSet = self::factory([new OsmPublicToilet()])->create(self::conflation());

        $this->assertSame('https://opendatacommons.org/licenses/odbl/1-0/', $dataSet->licence);
    }

    public function testAMergeCanNameItsLicence(): void
    {
        $dataSet = self::factory([new OsmPublicToilet(), new FindToiletPublicToilet()])->create(self::conflation(licence: 'https://opendatacommons.org/licenses/odbl/1-0/'));

        $this->assertSame('https://opendatacommons.org/licenses/odbl/1-0/', $dataSet->licence);
    }

    public function testInputsDeclaringDifferentContextsRequireTheMergeToNameOne(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('so it has to name its contextUrl');

        self::factory([new OsmPublicToilet(), new ToiletOfAnotherVersion()])->create(self::conflation());
    }

    public function testAMergeCanNameItsContext(): void
    {
        $context = new ToiletOfAnotherVersion()->definition->contextUrl;

        $dataSet = self::factory([new OsmPublicToilet(), new ToiletOfAnotherVersion()])->create(self::conflation(contextUrl: $context));

        $this->assertSame($context, $dataSet->contextUrl);
    }
}
