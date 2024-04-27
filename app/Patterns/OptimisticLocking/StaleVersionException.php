<?php

namespace App\Patterns\OptimisticLocking;

use RuntimeException;

/**
 * Thrown when a conditional update loses the version race, i.e. another writer
 * committed between our read and our write. The caller retries against fresh
 * state rather than silently overwriting the other change.
 */
class StaleVersionException extends RuntimeException
{
}
