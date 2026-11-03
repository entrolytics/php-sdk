<?php

declare(strict_types=1);

namespace Entrolytics\Exception;

/**
 * Thrown when request data is invalid.
 */
class ValidationException extends EntrolyticsException
{
    public function __construct(string $message = 'Invalid request data')
    {
        parent::__construct($message, 400);
    }
}
