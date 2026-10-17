<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A focal post and its collected replies. Immutable; round-trips through toArray()/fromArray().
 *
 * @implements IteratorAggregate<int, Tweet>
 */
final class Conversation implements Countable, IteratorAggregate
{
    /**
     * @param list<Tweet> $replies
     */
    public function __construct(
        public readonly Tweet $tweet,
        public readonly array $replies = [],
        public readonly ?FetchStats $stats = null,
    ) {
    }

    public function replyCount(): int
    {
        return count($this->replies);
    }

    public function count(): int
    {
        return count($this->replies);
    }

    /**
     * Replies that directly answer the focal tweet.
     *
     * @return list<Tweet>
     */
    public function directReplies(): array
    {
        $id = $this->tweet->id;
        return array_values(array_filter(
            $this->replies,
            static fn (Tweet $t): bool => $t->isReplyTo($id),
        ));
    }

    /**
     * Replies that answer other replies (nested in the discussion).
     *
     * @return list<Tweet>
     */
    public function nestedReplies(): array
    {
        $id = $this->tweet->id;
        return array_values(array_filter(
            $this->replies,
            static fn (Tweet $t): bool => !$t->isReplyTo($id),
        ));
    }

    /** Find a post by id, checking the focal tweet and all replies. */
    public function find(string $id): ?Tweet
    {
        if ($this->tweet->id === $id) {
            return $this->tweet;
        }
        foreach ($this->replies as $reply) {
            if ($reply->id === $id) {
                return $reply;
            }
        }
        return null;
    }

    public function hasReply(string $id): bool
    {
        foreach ($this->replies as $reply) {
            if ($reply->id === $id) {
                return true;
            }
        }
        return false;
    }

    public function withStats(FetchStats $stats): self
    {
        return new self($this->tweet, $this->replies, $stats);
    }

    /**
     * Merge additional replies into this conversation, preserving unique tweets.
     *
     * @param list<Tweet> $newReplies
     */
    public function withMergedReplies(array $newReplies): self
    {
        $merged = [];
        foreach ($this->replies as $r) {
            $merged[$r->id] = $r;
        }
        foreach ($newReplies as $r) {
            $merged[$r->id] = $r;
        }

        return new self($this->tweet, array_values($merged), $this->stats);
    }

    /** @return Traversable<int, Tweet> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->replies);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tweet' => $this->tweet->toArray(),
            'replies' => array_map(static fn (Tweet $t): array => $t->toArray(), $this->replies),
            'stats' => $this->stats?->toArray(),
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $statsData = $a['stats'] ?? null;
        $stats = is_array($statsData)
            ? new FetchStats(
                (int) ($statsData['requests'] ?? 0),
                (int) ($statsData['pages'] ?? 0),
                (int) ($statsData['newReplies'] ?? 0),
                (bool) ($statsData['fromCache'] ?? false),
                (bool) ($statsData['coalesced'] ?? false),
                isset($statsData['fetchedAt']) ? (int) $statsData['fetchedAt'] : null,
            )
            : null;

        return new self(
            Tweet::fromArray($a['tweet'] ?? []),
            array_map(static fn (array $t): Tweet => Tweet::fromArray($t), $a['replies'] ?? []),
            $stats,
        );
    }
}
