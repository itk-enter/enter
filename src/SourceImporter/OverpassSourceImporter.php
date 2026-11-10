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
        if (isset($data['remark'])) {
            throw new \RuntimeException(sprintf('Overpass returned a partial result: %s', $data['remark']));
        }

        $items = $data['elements'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException('The elements in the data are missing or not valid');
        }

        return $items;
    }
}
