<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The target division has no ideal-driver baseline, so market drivers cannot
 * be scored against it. A data gap the manager can route around (another
 * division), not a failure worth a retry.
 */
final class NoBaselineException extends \RuntimeException
{
}
