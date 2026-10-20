<?php

namespace App\SourceReader;

use App\Source\SourceInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SourceReader implements SourceReaderInterface
{
    use LoggerAwareTrait;
    use LoggerTrait;

    public function __construct(
        #[Autowire(service: FixedLifetimeCachingHttpClient::class)]
        private readonly HttpClientInterface $client,
    ) {
    }

    public function read(SourceInterface $source): iterable
    {
        // @todo Add some proper exception handling/logging.
        $definition = $source->definition;

        return $this->client->request(Request::METHOD_GET, $definition->accessUrlBase(), [
            'query' => $definition->accessUrlQuery(),
        ])->toArray();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
