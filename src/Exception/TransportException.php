<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Exception;

/** The HTTP request could not be completed (DNS, TLS, timeout, connection refused). */
class TransportException extends XScraperException
{
}
