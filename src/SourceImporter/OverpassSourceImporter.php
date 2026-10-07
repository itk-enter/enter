<?php

namespace App\SourceImporter;

use App\Source\DataType;
use App\Source\SourceInterface;

class OverpassSourceImporter extends AbstracSourceImporter
{
    public function supports(SourceInterface $source): bool
    {
        return DataType::Overpass === $source->definition->dataType;
    }

    protected function extractItems(iterable $data, SourceInterface $source): array
    {
        // Overpass answers a query that timed out or ran out of memory with
        // 200 and whatever it had found so far, saying so only in a remark.
        // Imported, that part would sweep away the rest.
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
