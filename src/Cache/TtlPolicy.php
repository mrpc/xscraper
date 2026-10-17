<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Cache;

use Mrpc\XScraper\Snowflake;

/**
 * Decides how long a fetched conversation counts as fresh, based on the post's age.
 *
 * Conversations move fast in the first hour and barely at all after a week, so a fixed
 * TTL either wastes requests on old posts or serves stale data for new ones. The age is
 * read from the snowflake id, so the decision itself costs nothing.
 */
final class TtlPolicy
{
    /**
     * @param array<int,int> $tiers  map of "post younger than N seconds" => "fresh for M seconds",
     *                               checked in ascending order
     * @param int            $oldest freshness for posts older than every tier
     */
    public function __construct(
        private array $tiers = [
            3600      => 120,     // < 1 hour old  -> 2 minutes
            21600     => 600,     // < 6 hours     -> 10 minutes
            86400     => 1800,    // < 1 day       -> 30 minutes
            604800    => 21600,   // < 1 week      -> 6 hours
        ],
        private int $oldest = 86400, // older        -> 1 day
    ) {
        ksort($this->tiers);
    }

    /** A policy with one fixed TTL for everything. */
    public static function fixed(int $seconds): self
    {
        return new self([], $seconds);
    }

    public function freshFor(string $tweetId, ?int $now = null): int
    {
        $age = Snowflake::ageSeconds($tweetId, $now);
        if ($age === null) {
            return $this->oldest;
        }
        foreach ($this->tiers as $maxAge => $ttl) {
            if ($age < $maxAge) {
                return $ttl;
            }
        }
        return $this->oldest;
    }
}
