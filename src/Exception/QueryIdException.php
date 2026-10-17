<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Exception;

/** The GraphQL query id is unknown to X (it rotates on redeploys) and could not be rediscovered. */
class QueryIdException extends XScraperException
{
}
