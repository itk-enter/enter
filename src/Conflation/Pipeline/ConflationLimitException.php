<?php

declare(strict_types=1);

namespace App\Conflation\Pipeline;

/**
 * A step returned a result outside the limits the pipeline sets for it, so
 * the run fails without publishing.
 */
final class ConflationLimitException extends \RuntimeException
{
}
