<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Model;

use DateTimeImmutable;

/** A post (tweet). Immutable; round-trips through toArray()/fromArray() for caching. */
final class Tweet
{
    /**
     * @param list<Media>  $media
     * @param list<string> $urls     expanded link URLs
     * @param list<string> $hashtags without '#'
     * @param list<string> $mentions screen names without '@'
     */
    public function __construct(
        public readonly string $id,
        public readonly string $conversationId,
        public readonly ?string $inReplyToId,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly string $text,
        public readonly ?string $lang,
        public readonly User $user,
        public readonly int $likes = 0,
        public readonly int $retweets = 0,
        public readonly int $replies = 0,
        public readonly int $quotes = 0,
        public readonly int $bookmarks = 0,
        public readonly ?int $views = null,
        public readonly array $media = [],
        public readonly array $urls = [],
        public readonly array $hashtags = [],
        public readonly array $mentions = [],
        public readonly ?string $quotedId = null,
    ) {
    }

    public function url(): string
    {
        return 'https://x.com/' . ($this->user->screenName ?? 'i') . '/status/' . $this->id;
    }

    public function isReplyTo(string $tweetId): bool
    {
        return $this->inReplyToId === $tweetId;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'in_reply_to' => $this->inReplyToId,
            'created_at' => $this->createdAt?->format(DATE_ATOM),
            'text' => $this->text,
            'lang' => $this->lang,
            'user' => $this->user->toArray(),
            'likes' => $this->likes,
            'retweets' => $this->retweets,
            'replies' => $this->replies,
            'quotes' => $this->quotes,
            'bookmarks' => $this->bookmarks,
            'views' => $this->views,
            'media' => array_map(static fn (Media $m) => $m->toArray(), $this->media),
            'urls' => $this->urls,
            'hashtags' => $this->hashtags,
            'mentions' => $this->mentions,
            'quoted_id' => $this->quotedId,
            'url' => $this->url(),
        ];
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['id'],
            (string) ($a['conversation_id'] ?? $a['id']),
            $a['in_reply_to'] ?? null,
            isset($a['created_at']) ? new DateTimeImmutable($a['created_at']) : null,
            (string) ($a['text'] ?? ''),
            $a['lang'] ?? null,
            User::fromArray($a['user'] ?? []),
            (int) ($a['likes'] ?? 0),
            (int) ($a['retweets'] ?? 0),
            (int) ($a['replies'] ?? 0),
            (int) ($a['quotes'] ?? 0),
            (int) ($a['bookmarks'] ?? 0),
            isset($a['views']) ? (int) $a['views'] : null,
            array_map(static fn (array $m) => Media::fromArray($m), $a['media'] ?? []),
            $a['urls'] ?? [],
            $a['hashtags'] ?? [],
            $a['mentions'] ?? [],
            $a['quoted_id'] ?? null,
        );
    }
}
