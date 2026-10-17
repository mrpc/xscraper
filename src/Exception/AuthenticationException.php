<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Exception;

/** X rejected the session cookies (401/403). Refresh auth_token and ct0. */
class AuthenticationException extends XScraperException
{
}
