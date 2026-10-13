<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * A step returned a result that breaks the limits its ADR sets, so the run
 * fails without publishing.
 */
final class ConflationLimitException extends \RuntimeException
{
}
