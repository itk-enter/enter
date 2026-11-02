<?php

declare(strict_types=1);

namespace App\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Skraafoto\SkraafotoClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gives the newest oblique aerial photo (skråfoto) of a point, from the
 * Skråfoto API of Dataforsyningen.
 *
 * The image is the address of the thumbnail route of this application. The
 * route gets the thumbnail with the token, so the token stays on the server.
 * The address is absolute and starts with DEFAULT_URI.
 *
 * Skråfoto adds new photos approximately once a year. Thus a point without a
 * photo is checked again after 30 days, and a photo is checked again after
 * 180 days. The lookups transport of this service allows 5 requests each
 * second.
 */
#[AsLocationLookup(
    id: 'aerial-photos',
    attributes: ['image', 'imageDate'],
    recheckEmptyAfter: 'P30D',
    refreshAfter: 'P180D',
    transport: 'lookup_aerial_photos',
)]
final readonly class NewestAerialPhoto implements LocationLookupInterface
{
    public function __construct(
        private SkraafotoClient $client,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function lookup(float $longitude, float $latitude): ?array
    {
        $collection = $this->client->newestCollection();
        $photo = $this->client->photoAt($collection, $longitude, $latitude);
        $id = $photo['id'] ?? null;
        if (!\is_string($id)) {
            return null;
        }

        $taken = $photo['properties']['datetime'] ?? null;

        return [
            'image' => $this->urls->generate('app_skraafoto_thumbnail', ['collection' => $collection, 'photo' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
            'imageDate' => \is_string($taken) ? substr($taken, 0, 10) : null,
        ];
    }
}
