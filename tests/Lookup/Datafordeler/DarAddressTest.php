<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Datafordeler;

use App\Conflation\Lookup\AsLocationLookup;
use App\Geo\Wgs84Transformer;
use App\Lookup\Datafordeler\DarAddress;
use App\Lookup\Datafordeler\DatafordelerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DarAddressTest extends TestCase
{
    private const float LONGITUDE = 10.2069;
    private const float LATITUDE = 56.1590;

    /** @var list<array<string, mixed>> the variables of each query that the lookup sent */
    private array $sent = [];

    /**
     * A lookup with a mock Datafordeleren that answers each query in turn.
     *
     * @param list<array<string, mixed>> $answers the "data" of each answer
     */
    private function lookup(array $answers): DarAddress
    {
        $this->sent = [];
        $responses = array_map(fn (array $data): \Closure => function (string $method, string $url, array $options) use ($data): MockResponse {
            $this->sent[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)['variables'];

            return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR));
        }, $answers);

        $client = new DatafordelerClient(new MockHttpClient($responses, 'https://graphql.datafordeler.dk/'));

        return new DarAddress($client, new Wgs84Transformer(), new MockClock('2026-11-06 12:00:00 UTC'));
    }

    /**
     * An access point at an offset in metres from the point of the test.
     *
     * @return array<string, mixed>
     */
    private static function accessPoint(string $id, float $east, float $north): array
    {
        [$x, $y] = new Wgs84Transformer()->fromWgs84('EPSG:25832', self::LONGITUDE, self::LATITUDE);

        return ['id_lokalId' => $id, 'position' => ['wkt' => \sprintf('POINT (%F %F)', $x + $east, $y + $north)]];
    }

    /**
     * @param array<string, mixed> ...$nodes
     *
     * @return array<string, mixed>
     */
    private static function accessPoints(array ...$nodes): array
    {
        return ['DAR_Adressepunkt' => ['nodes' => $nodes]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function houseNumber(string $accessPoint, string $street, string $number, string $status = DarAddress::IN_USE, ?string $town = null): array
    {
        return [
            'adgangspunkt' => $accessPoint,
            'status' => $status,
            'husnummertekst' => $number,
            'husnummerHoererTilNavngivenVej' => ['vejnavn' => $street],
            'husnummerHoererTilIPostnummer' => ['postnr' => '8000', 'navn' => 'Aarhus C'],
            'husnummerHoererTilSupplerendeBynavn' => null === $town ? null : ['navn' => $town],
        ];
    }

    /**
     * @param array<string, mixed> ...$nodes
     *
     * @return array<string, mixed>
     */
    private static function houseNumbers(array ...$nodes): array
    {
        return ['DAR_Husnummer' => ['nodes' => $nodes]];
    }

    public function testTheLookupGivesTheAddressOfTheNearestAccessPoint(): void
    {
        $lookup = $this->lookup([
            self::accessPoints(self::accessPoint('far', 12.0, 0.0), self::accessPoint('near', 0.0, 5.0)),
            self::houseNumbers(self::houseNumber('far', 'Klostergade', '30'), self::houseNumber('near', 'Klostergade', '28')),
        ]);

        $this->assertSame(['address' => [
            'streetAddress' => 'Klostergade 28',
            'postalCode' => '8000',
            'addressLocality' => 'Aarhus C',
            'addressCountry' => 'DK',
        ]], $lookup->lookup(self::LONGITUDE, self::LATITUDE));
        $this->assertSame(['near', 'far'], $this->sent[1]['accessPoints'], 'The lookup asks for the access points by distance.');
        $this->assertSame('2026-11-06T12:00:00Z', $this->sent[0]['now']);
    }

    public function testTheSupplementaryTownNameIsTheDistrict(): void
    {
        $lookup = $this->lookup([
            self::accessPoints(self::accessPoint('a', 1.0, 1.0)),
            self::houseNumbers(self::houseNumber('a', 'Skovvejen', '2', town: 'Holme')),
        ]);

        $this->assertSame('Holme', $lookup->lookup(self::LONGITUDE, self::LATITUDE)['address']['district'] ?? null);
    }

    public function testAnAccessPointInTheCornerOfTheSquareButOutsideTheCircleIsIgnored(): void
    {
        $lookup = $this->lookup([
            self::accessPoints(self::accessPoint('corner', 18.0, 18.0)),
            self::accessPoints(self::accessPoint('corner', 18.0, 18.0)),
            self::houseNumbers(self::houseNumber('corner', 'Klostergade', '28')),
        ]);

        $this->assertSame('Klostergade 28', $lookup->lookup(self::LONGITUDE, self::LATITUDE)['address']['streetAddress'] ?? null);
        $this->assertCount(3, $this->sent, 'The 20 m step finds no access point in its circle, so the 50 m step asks again.');
    }

    public function testAHouseNumberThatIsNotInUseIsIgnored(): void
    {
        $lookup = $this->lookup([
            self::accessPoints(self::accessPoint('retired', 1.0, 0.0), self::accessPoint('current', 10.0, 0.0)),
            self::houseNumbers(self::houseNumber('retired', 'Gammel Vej', '1', status: '4'), self::houseNumber('current', 'Ny Vej', '2')),
        ]);

        $this->assertSame('Ny Vej 2', $lookup->lookup(self::LONGITUDE, self::LATITUDE)['address']['streetAddress'] ?? null);
    }

    public function testTheLookupSearchesFurtherInStepsAndStopsAt80Metres(): void
    {
        $lookup = $this->lookup([self::accessPoints(), self::accessPoints(), self::accessPoints()]);

        $this->assertNull($lookup->lookup(self::LONGITUDE, self::LATITUDE));
        $this->assertCount(3, $this->sent);
        foreach ([20, 50, 80] as $i => $radius) {
            preg_match_all('/[\d.]+/', $this->sent[$i]['area'], $numbers);
            $this->assertEqualsWithDelta(2 * $radius, (float) $numbers[0][2] - (float) $numbers[0][0], 0.001);
        }
    }

    public function testAStepWithoutAHouseNumberInUseGoesOnToTheNextStep(): void
    {
        $lookup = $this->lookup([
            self::accessPoints(self::accessPoint('retired', 1.0, 0.0)),
            self::houseNumbers(self::houseNumber('retired', 'Gammel Vej', '1', status: '4')),
            self::accessPoints(self::accessPoint('retired', 1.0, 0.0), self::accessPoint('current', 40.0, 0.0)),
            self::houseNumbers(self::houseNumber('retired', 'Gammel Vej', '1', status: '4'), self::houseNumber('current', 'Ny Vej', '2')),
        ]);

        $this->assertSame('Ny Vej 2', $lookup->lookup(self::LONGITUDE, self::LATITUDE)['address']['streetAddress'] ?? null);
    }

    public function testTheLookupUsesTheDatafordelerTransport(): void
    {
        $config = AsLocationLookup::of(DarAddress::class);

        $this->assertSame('datafordeler', $config->transport);
        $this->assertSame(['address'], $config->attributes);
    }
}
