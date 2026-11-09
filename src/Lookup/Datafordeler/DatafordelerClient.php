<?php

declare(strict_types=1);

namespace App\Lookup\Datafordeler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends GraphQL queries to Datafordeleren.
 *
 * The queries go to the endpoint "flexibleCurrent", which joins the entities
 * of the registers. The scoped client "datafordeler.client" adds the API key
 * from DATAFORDELER_API_KEY to the query string, because Datafordeleren
 * accepts the key only there. Thus the URL holds the key. The client removes
 * the key from the messages of its exceptions, and RedactApiKey removes it
 * from the logs of the HTTP client.
 *
 * @see https://confluence.kds.dk/pages/viewpage.action?pageId=187105434
 */
final readonly class DatafordelerClient
{
    public const string ENDPOINT = 'flexibleCurrent/v1';

    /**
     * The format of a DafDateTime: UTC, ISO 8601.
     */
    public const string TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private HttpClientInterface $datafordelerClient,
    ) {
    }

    /**
     * Sends one query.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed> the "data" of the response
     *
     * @throws \RuntimeException if the request fails, or if the response has GraphQL errors
     */
    public function query(string $query, array $variables = []): array
    {
        try {
            $body = $this->datafordelerClient->request(Request::METHOD_POST, self::ENDPOINT, [
                'json' => ['query' => $query, 'variables' => (object) $variables],
            ])->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw new \RuntimeException(\sprintf('Datafordeleren returned HTTP %d.', $exception->getResponse()->getStatusCode()));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException(\sprintf('The request to Datafordeleren failed: %s', self::redact($exception->getMessage())));
        }

        $errors = $body['errors'] ?? [];
        if (\is_array($errors) && [] !== $errors) {
            $messages = array_map(static fn (mixed $error): string => \is_array($error) ? (string) ($error['message'] ?? '') : '', $errors);

            throw new \RuntimeException(\sprintf('Datafordeleren returned errors: %s', self::redact(implode(' ', $messages))));
        }

        $data = $body['data'] ?? null;

        return \is_array($data) ? $data : [];
    }

    /**
     * Replaces the value of each "apiKey" parameter in a text with "***".
     */
    public static function redact(string $text): string
    {
        return (string) preg_replace('/(apiKey=)[^&\s"\']+/i', '$1***', $text);
    }
}
