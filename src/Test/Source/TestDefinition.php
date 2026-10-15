<?php

declare(strict_types=1);

namespace App\Test\Source;

use App\Source\DataType;
use App\Source\Definition;

#[\Attribute(\Attribute::TARGET_CLASS)]
readonly class TestDefinition extends Definition
{
    /**
     * @param array<string, mixed> $dataUrlQuery
     */
    public function __construct(
        string $id,
        string $title,
        string $accessUrl,
        DataType $dataType,
        string $mediaType,
        string $crs,
        string $model,
        string $contextUrl,
        array $omittedFields,
        public string $dataUrlBase,
        public array $dataUrlQuery = [],
    ) {
        parent::__construct(
            id: $id,
            title: $title,
            description: '',
            publisher: '',
            contact: '',
            landingPage: '',
            accessUrl: $accessUrl,
            dataType: $dataType,
            mediaType: $mediaType,
            crs: $crs,
            model: $model,
            contextUrl: $contextUrl,
            updateFrequency: '',
            licence: '',
            omittedFields: $omittedFields,
        );
    }
}
