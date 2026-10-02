<?php

namespace App\SourceReader;

use App\Source\SourceInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SourceReader implements SourceReaderInterface
{
    use LoggerAwareTrait;
    use LoggerTrait;

    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire(service: 'source_reader.cache')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Sources that share an access URL share one response, so a feed split
     * over several sources is fetched once while the cached copy lasts,
     * although each source is imported in a process of its own.
     */
    public function read(SourceInterface $source): iterable
    {
        // @todo Add some proper exception handling/logging.
        $definition = $source->definition;

        return $this->cache->get(
            hash('xxh128', $definition->accessUrlWithQuery()),
            fn (): array => $this->client->request('GET', $definition->accessUrlBase(), [
                'query' => $definition->accessUrlQuery(),
            ])->toArray()
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
