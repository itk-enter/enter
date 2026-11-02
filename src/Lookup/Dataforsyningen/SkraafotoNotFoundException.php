<?php

declare(strict_types=1);

namespace App\Lookup\Dataforsyningen;

/**
 * The Skråfoto API has no photo or no thumbnail for the request.
 */
final class SkraafotoNotFoundException extends \RuntimeException
{
}
