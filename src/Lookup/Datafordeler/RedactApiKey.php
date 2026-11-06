<?php

declare(strict_types=1);

namespace App\Lookup\Datafordeler;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Removes the API key of Datafordeleren from the logs of the HTTP client.
 *
 * The HTTP client logs the URL of each request and response, in the message
 * or in the context. The URL of a request to Datafordeleren holds the API key
 * in the query string.
 *
 * @see https://symfony.com/doc/current/logging/processors.html
 */
#[AsMonologProcessor(channel: 'http_client')]
final readonly class RedactApiKey
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = array_map(
            static fn (mixed $value): mixed => \is_string($value) ? DatafordelerClient::redact($value) : $value,
            $record->context,
        );

        return $record->with(message: DatafordelerClient::redact($record->message), context: $context);
    }
}
