<?php

declare(strict_types=1);

namespace Entrolytics\Exception;

/**
 * Thrown when network request fails.
 */
class NetworkException extends EntrolyticsException
{
    public function __construct(string $message = 'Network request failed', ?\Throwable $previous = null)
    {
        parent::__construct($message, null, $previous);
    }
}
