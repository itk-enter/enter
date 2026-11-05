<?php

declare(strict_types=1);

namespace App\Lookup\Dataforsyningen;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\GeometryHelper;
use App\GeometryHelper\Crs;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gives the address of an orthophoto of a point, for an HTML img element.
 *
 * The image is the route /orthophoto/{x},{y}.jpg of this application, with
 * the point in UTM 32, in whole metres. The route gets the image from the WMS
 * of Dataforsyningen with the token, so the token stays on the server. The
 * address is absolute and starts with DEFAULT_URI.
 *
 * The lookup makes no request, so it has no transport, and the run asks it
 * for each merged entity. The route accepts a point only with 6 digits for
 * x and 7 digits for y, as in Denmark. A point with other coordinates gets
 * no image.
 */
#[AsLocationLookup(id: 'orthophotos', attributes: ['image'])]
final readonly class Orthophoto implements LocationLookupInterface
{
    public function __construct(
        private GeometryHelper $geometry,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function lookup(float $longitude, float $latitude): ?array
    {
        [$x, $y] = $this->utm($longitude, $latitude);
        if (1 !== preg_match('/^\d{6}$/', (string) $x) || 1 !== preg_match('/^\d{7}$/', (string) $y)) {
            return null;
        }

        return ['image' => $this->urls->generate('app_orthophoto', ['x' => $x, 'y' => $y], UrlGeneratorInterface::ABSOLUTE_URL)];
    }

    /**
     * @return array{int, int} the point in UTM 32, in whole metres
     */
    private function utm(float $longitude, float $latitude): array
    {
        [$x, $y] = $this->geometry->transformCoordinates($longitude, $latitude, Crs::EPSG_4326, Crs::EPSG_25832);

        return [(int) round($x), (int) round($y)];
    }
}
