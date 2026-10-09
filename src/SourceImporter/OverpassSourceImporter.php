<?php

namespace App\SourceImporter;

use App\Source\DataType;
use App\Source\SourceInterface;

class OverpassSourceImporter extends AbstractSourceImporter
{
    public function supports(SourceInterface $source): bool
    {
        return DataType::Overpass === $source->definition->dataType;
    }

    protected function extractItems(iterable $data, SourceInterface $source): array
    {
        // Fail if Overpass returned only part of the result. When a query
        // times out or runs out of memory, Overpass still answers 200 with
        // what it found so far, and says so only in a "remark" field.
        if (isset($data['remark'])) {
            throw new \RuntimeException(sprintf('Overpass returned a partial result: %s', $data['remark']));
        }

        $items = $data['elements'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('Invalid or missing elements in data');
        }

        return $items;
    }
}
