<?php

declare(strict_types=1);

namespace App\Test\Fixture;

/**
 * The result of one fixture fetch.
 */
final readonly class FixtureFetch
{
    /**
     * @param string $url      the live feed
     * @param string $filename the fixture file
     */
    public function __construct(
        public string $url,
        public string $filename,
    ) {
    }
}
