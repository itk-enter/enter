<?php

declare(strict_types=1);

namespace App\Conflation\Pipeline;

/**
 * A step returned a result outside the limits that the pipeline sets for
 * it, so the run fails and publishes nothing.
 */
final class ConflationLimitException extends \RuntimeException
{
}
