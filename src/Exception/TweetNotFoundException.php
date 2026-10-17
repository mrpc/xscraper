<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Exception;

/** The post does not exist, was deleted, or is not visible to this account. */
class TweetNotFoundException extends XScraperException
{
    public function __construct(public readonly string $tweetId, string $message = "")
    {
        parent::__construct($message !== "" ? $message : "Post $tweetId not found or not visible");
    }
}
