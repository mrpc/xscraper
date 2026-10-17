<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Exception;

/** Another process held the lock for this post longer than the configured lock timeout. */
class LockTimeoutException extends XScraperException
{
}
