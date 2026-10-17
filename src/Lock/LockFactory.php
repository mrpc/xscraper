<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Lock;

/** Provides named, cross-process mutual exclusion. */
interface LockFactory
{
    /**
     * Run $callback while holding the lock called $name.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws \Mrpc\XScraper\Exception\LockTimeoutException
     */
    public function synchronized(string $name, float $timeoutSeconds, callable $callback): mixed;
}
