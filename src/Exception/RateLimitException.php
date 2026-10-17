<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Exception;

/** The request budget is exhausted. Nothing was sent; wait until resetAt. */
class RateLimitException extends XScraperException
{
    public function __construct(string $message, public readonly ?int $resetAt = null)
    {
        parent::__construct($message);
    }

    /** Seconds to wait before the next attempt (0 if unknown or already passed). */
    public function retryAfter(): int
    {
        return $this->resetAt === null ? 0 : max(0, $this->resetAt - time());
    }
}
