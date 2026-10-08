<?php

declare(strict_types=1);

namespace App\Test\Fixture;

use App\Source\DataType;
use App\Source\SourceInterface;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Refreshes a test source's fixture from the live feed it stands in for.
 *
 * A response is kept for a while, so a run that failed halfway can be run
 * again without fetching what already succeeded, and sources splitting one
 * feed fetch it once. A fixture is only replaced by a complete response; a
 * failed fetch leaves the old one in place.
 */
final readonly class FixtureFetcher
{
    public function __construct(
        #[Autowire(service: 'test_fixture.client')]
        private HttpClientInterface $client,
        #[Autowire(service: 'test_fixture.cache')]
        private CacheInterface $cache,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/tests/resources')]
        private string $directory,
    ) {
    }

    /**
     * @param bool $fresh fetch the feed even when a response is kept
     */
    public function fetch(SourceInterface $source, bool $fresh = false): FixtureFetch
    {
        $definition = $source->definition;
        if (!$definition instanceof TestDefinition) {
            throw new \InvalidArgumentException(\sprintf('Source %s is not a test source.', $definition->id));
        }

        $url = $definition->dataUrlBase;
        $query = $definition->dataUrlQuery;

        $fetched = false;
        $content = $this->cache->get(
            hash('xxh128', $url.'?'.http_build_query($query)),
            function () use ($definition, $url, $query, &$fetched): string {
                $fetched = true;
                $content = $this->client->request('GET', $url, ['query' => $query])->getContent();
                $this->assertComplete($definition, $content);

                return $content;
            },
            $fresh ? \INF : null,
        );

        $filename = $this->filename($definition);
        $this->filesystem->dumpFile($filename, $content);

        return new FixtureFetch($url, $filename, !$fetched);
    }

    /**
     * Overpass answers a query that timed out or ran out of memory with 200
     * and whatever it had found so far, saying so only in a remark.
     */
    private function assertComplete(TestDefinition $definition, string $content): void
    {
        if (DataType::Overpass !== $definition->dataType) {
            return;
        }

        $data = json_decode($content, true);
        if (\is_array($data) && isset($data['remark'])) {
            throw new \RuntimeException(\sprintf('Overpass returned a partial result: %s', $data['remark']));
        }
    }

    /**
     * The fixture file the source reads, named by its access URL, whose
     * path must start with /test/.
     */
    private function filename(TestDefinition $definition): string
    {
        $path = preg_replace('@^[a-z]+://[^/]+/test/@', '', $definition->accessUrl);
        if ($path === $definition->accessUrl) {
            throw new \RuntimeException(\sprintf('Invalid test source URL: %s. Its path must start with "/test/".', $definition->accessUrl));
        }

        return $this->directory.'/'.$path;
    }
}
