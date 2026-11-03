<?php

declare(strict_types=1);

namespace Entrolytics\Exception;

/**
 * Thrown when rate limit is exceeded.
 */
class RateLimitException extends EntrolyticsException
{
    protected ?int $retryAfter;

    public function __construct(string $message = 'Rate limit exceeded', ?int $retryAfter = null)
    {
        parent::__construct($message, 429);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
