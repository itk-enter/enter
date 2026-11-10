<?php

namespace App\SourceReader;

use App\Source\SourceInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(SourceReaderInterface::class)]
final class SourceReader implements SourceReaderInterface
{
    use LoggerAwareTrait;
    use LoggerTrait;

    public function __construct(
        #[Autowire(service: FixedLifetimeCachingHttpClient::class)]
        private readonly HttpClientInterface $client,
    ) {
    }

    public function read(SourceInterface $source): FeedResponse
    {
        // @todo If the read fails, throw an exception that names the source.
        //   Use the same approach as UpsertFailedException.
        $definition = $source->definition;

        $response = $this->client->request(Request::METHOD_GET, $definition->accessUrlBase(), [
            'query' => $definition->accessUrlQuery(),
        ]);

        return new FeedResponse($response->getContent(), $response->getHeaders()['content-type'][0] ?? null);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
