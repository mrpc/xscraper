<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Model;

/** Result of parsing a TweetDetail GraphQL timeline response. */
final class TimelineResult
{
    /**
     * @param list<Tweet> $replies
     */
    public function __construct(
        public readonly ?Tweet $focalTweet,
        public readonly array $replies = [],
        public readonly ?string $bottomCursor = null,
        public readonly ?string $topCursor = null,
    ) {
    }
}
