<?php

namespace App\SourceImporter;

use App\Source\DataType;
use App\Source\SourceInterface;

class GetJsonSourceImporter extends AbstracSourceImporter
{
    public function supports(SourceInterface $source): bool
    {
        return DataType::GeoJSON === $source->definition->dataType;
    }

    protected function extractItems(iterable $data, SourceInterface $source): array
    {
        $items = $data['features'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('Invalid or missing features in data');
        }

        return $items;
    }
}
