<?php

namespace App\SourceReader;

use App\Source\SourceInterface;

interface SourceReaderInterface
{
    /**
     * Reads the feed of a source.
     *
     * The import calls this method one time for each source. Return the body
     * as the source sent it, without decoding. The import decodes it with
     * FeedResponse::toArray(). Throw an exception if the read fails.
     */
    public function read(SourceInterface $source): FeedResponse;
}
