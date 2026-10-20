<?php

declare(strict_types=1);

namespace App\Source;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Converts one input data set into NGSI-LD entities.
 *
 * An implementation owns its feed's origin, field names, quirks and target
 * Smart Data Model. The broker and the import know none of that, so a new
 * ENTER data set costs exactly one class.
 */
#[AutoconfigureTag('app.source')]
interface SourceInterface extends \Stringable, \JsonSerializable
{
    public Definition $definition {
        get;
    }

    /**
     * Maps one feed record onto an NgsiEntity.
     *
     * @param array<string, mixed> $data
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
