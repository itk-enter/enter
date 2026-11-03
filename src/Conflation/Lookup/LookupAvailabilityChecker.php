<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Asks each lookup about one point, to find out if its service is available.
 *
 * The checker calls the lookup directly, not through the queue, so it does not
 * wait for the rate limiter. It uses the same configuration as the worker, for
 * example the token of the service. A service that gives no answer for the
 * point is available. A service that throws an exception is not. If the
 * lookup implements AvailabilityCheckInterface, the checker also calls
 * checkAvailability().
 */
final readonly class LookupAvailabilityChecker
{
    /**
     * A point in the centre of Aarhus: the city hall.
     */
    public const array DEFAULT_POINT = [10.2034, 56.1530];

    /**
     * @param iterable<LocationLookupInterface> $lookups
     */
    public function __construct(
        #[AutowireIterator('app.location_lookup')]
        private iterable $lookups,
    ) {
    }

    /**
     * Asks each lookup about the point.
     *
     * @return list<LookupAvailability> one result for each lookup, ordered by the id of the lookup
     */
    public function checkAll(float $longitude, float $latitude): array
    {
        $results = [];
        foreach ($this->lookups as $lookup) {
            $config = AsLocationLookup::of($lookup::class);
            $start = hrtime(true);
            try {
                $answer = $lookup->lookup($longitude, $latitude);
                if ($lookup instanceof AvailabilityCheckInterface) {
                    $lookup->checkAvailability($longitude, $latitude);
                }
                $error = null;
            } catch (\Throwable $exception) {
                $answer = null;
                $error = $exception->getMessage();
            }
            $results[] = new LookupAvailability($config->id, $config->transport, $answer, $error, (hrtime(true) - $start) / 1e6);
        }
        usort($results, static fn (LookupAvailability $x, LookupAvailability $y): int => strcmp($x->id, $y->id));

        return $results;
    }
}
