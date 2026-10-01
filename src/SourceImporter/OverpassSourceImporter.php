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
        $items = $data['elements'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('Invalid or missing elements in data');
        }

        return $items;
    }
}
