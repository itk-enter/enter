<?php

namespace App\SourceImporter;

use App\Source\DataType;
use App\Source\SourceInterface;

class FindToiletSourceImporter extends AbstracSourceImporter
{
    public function supports(SourceInterface $source): bool
    {
        return DataType::FindToilet === $source->definition->dataType;
    }

    protected function extractItems(iterable $data, SourceInterface $source): array
    {
        $items = $data['toilets'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('Invalid or missing toilets in data');
        }

        return $items;
    }
}
