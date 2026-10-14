<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * Two records from different data sets within matching range of each other.
 */
final readonly class Pair
{
    public function __construct(
        public Record $a,
        public Record $b,
        public float $distance,
    ) {
    }
}
