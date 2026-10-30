<?php

declare(strict_types=1);

namespace App\Test\Fixture;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Finds the fixture files that the test sources read.
 *
 * The URL path of a test source after "/test/" is the path of its fixture
 * file in tests/resources. The file name keeps the query of the URL, for
 * example "data/overpass-api.de/api/interpreter?osm-bench".
 */
#[When('dev')]
#[When('test')]
final readonly class FixtureFiles
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/tests/resources')]
        private string $directory,
    ) {
    }

    /**
     * Gets the absolute path of a fixture file.
     *
     * @param string $path the path of the file in tests/resources
     *
     * @return string|null the absolute path, or null if the file does not
     *                     exist or is not in tests/resources
     */
    public function find(string $path): ?string
    {
        $directory = realpath($this->directory);
        $file = realpath($this->directory.'/'.$path);

        if (false === $directory || false === $file || !is_file($file) || !str_starts_with($file, $directory.\DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
    }
}
