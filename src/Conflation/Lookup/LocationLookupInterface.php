<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A remote service that gives details about one coordinate.
 *
 * A lookup is a class that has the #[AsLocationLookup] attribute and
 * implements this interface. A merge names the lookups that it uses in the
 * "lookups" option of its #[Conflation] attribute. A worker asks the lookup
 * after the merge run, and writes the answer to the merged entity.
 *
 * For example, a service gives the newest aerial photo of a point:
 *
 *     #[AsLocationLookup(id: 'aerial-photos', attributes: ['image', 'imageDate'],
 *         recheckEmptyAfter: 'P7D', refreshAfter: 'P90D')]
 *     final readonly class NearestAerialPhoto implements LocationLookupInterface
 *     {
 *         public function lookup(float $longitude, float $latitude): ?array
 *         {
 *             $photo = $this->client->newestPhoto($longitude, $latitude);
 *
 *             return null === $photo ? null : ['image' => $photo->url, 'imageDate' => $photo->date];
 *         }
 *     }
 *
 * @see AsLocationLookup
 */
#[AutoconfigureTag('app.location_lookup')]
interface LocationLookupInterface
{
    /**
     * Asks the service about one coordinate.
     *
     * The worker calls the method once for each message. If the method throws
     * an exception, the worker tries the message again later.
     *
     * @param float $longitude WGS 84 longitude
     * @param float $latitude  WGS 84 latitude
     *
     * @return array<string, mixed>|null the values by attribute name, with only the attributes that the
     *                                   #[AsLocationLookup] attribute declares, or null if the service has no
     *                                   answer for the coordinate
     */
    public function lookup(float $longitude, float $latitude): ?array;
}
