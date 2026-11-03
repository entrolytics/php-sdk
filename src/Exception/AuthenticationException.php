<?php

declare(strict_types=1);

namespace Entrolytics\Exception;

/**
 * Thrown when API key is invalid or missing.
 */
class AuthenticationException extends EntrolyticsException
{
    public function __construct(string $message = 'Invalid or missing API key')
    {
        parent::__construct($message, 401);
    }
}
