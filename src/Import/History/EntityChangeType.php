<?php

declare(strict_types=1);

namespace App\Import\History;

/**
 * How an import changed one entity of its source in the broker.
 */
enum EntityChangeType: string
{
    /**
     * The feed gave the entity, and the broker did not have it before the
     * import.
     */
    case Added = 'added';

    /**
     * The broker had the entity, and the feed no longer gave it. The import
     * deleted it.
     */
    case Removed = 'removed';
}
