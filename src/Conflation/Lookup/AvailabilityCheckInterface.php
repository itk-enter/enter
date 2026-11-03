<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

/**
 * A lookup whose answer does not show if its remote service is available.
 *
 * For example, a lookup can build the URL of an image without a request.
 * The service that gives the image is then not tested by the lookup.
 * LookupAvailabilityChecker calls checkAvailability() after lookup() for such
 * a lookup, so that app:lookup:check also tests the service.
 */
interface AvailabilityCheckInterface
{
    /**
     * Sends one request to the remote service about a point.
     *
     * LookupAvailabilityChecker calls the method once for each check.
     *
     * @param float $longitude WGS 84 longitude
     * @param float $latitude  WGS 84 latitude
     *
     * @throws \Throwable if the service is not available, or if its answer is not valid
     */
    public function checkAvailability(float $longitude, float $latitude): void;
}
