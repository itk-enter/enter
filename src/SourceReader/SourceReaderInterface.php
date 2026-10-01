<?php

namespace App\SourceReader;

use App\Source\SourceInterface;

interface SourceReaderInterface
{
    /**
     * @return iterable<mixed>
     */
    public function read(SourceInterface $source): iterable;
}
