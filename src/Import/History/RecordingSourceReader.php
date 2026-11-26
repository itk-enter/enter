<?php

declare(strict_types=1);

namespace App\Import\History;

use App\Source\SourceInterface;
use App\SourceReader\FeedResponse;
use App\SourceReader\SourceReader;
use App\SourceReader\SourceReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Gives each feed that the source reader reads to the import history.
 *
 * Only the reader sees the raw body of a feed. Thus this class decorates the
 * reader, and the importers need no history code.
 */
#[AsDecorator(decorates: SourceReader::class)]
final readonly class RecordingSourceReader implements SourceReaderInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SourceReaderInterface $inner,
        private ImportRecorder $recorder,
    ) {
    }

    public function read(SourceInterface $source): FeedResponse
    {
        $response = $this->inner->read($source);
        $this->recorder->recordFeed($source, $response);

        return $response;
    }
}
