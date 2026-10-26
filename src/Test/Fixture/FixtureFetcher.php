<?php

declare(strict_types=1);

namespace App\Test\Fixture;

use App\Source\DataType;
use App\Source\SourceInterface;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Updates the fixture of a test source from the live feed.
 */
final readonly class FixtureFetcher
{
    public function __construct(
        #[Autowire(service: 'test_fixture.client')]
        private HttpClientInterface $client,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/tests/resources')]
        private string $directory,
    ) {
    }

    /**
     * Fetches the live feed and writes the fixture.
     */
    public function fetch(SourceInterface $source): FixtureFetch
    {
        $definition = $source->definition;
        if (!$definition instanceof TestDefinition) {
            throw new \InvalidArgumentException(\sprintf('Source %s is not a test source.', $definition->id));
        }

        $url = $definition->dataUrlBase;
        $content = $this->client->request(Request::METHOD_GET, $url, ['query' => $definition->dataUrlQuery])->getContent();
        $this->assertComplete($definition, $content);

        $filename = $this->filename($definition);
        $this->filesystem->dumpFile($filename, $content);

        return new FixtureFetch($url, $filename);
    }

    /**
     * Fails if Overpass gives a partial result.
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
     * Gets the fixture file name from the access URL.
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
