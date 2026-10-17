<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Model;

/** What a call cost and where its data came from. */
final class FetchStats
{
    public function __construct(
        /** HTTP requests sent to X during this call (0 = served entirely from cache). */
        public readonly int $requests,
        /** Pages that were fetched for this call. */
        public readonly int $pages,
        /** Replies seen for the first time during this call. */
        public readonly int $newReplies,
        /** True when no network request was needed. */
        public readonly bool $fromCache,
        /** True when another process was already fetching this post and we reused its result. */
        public readonly bool $coalesced,
        /** Unix time of the last successful network refresh of this conversation. */
        public readonly ?int $fetchedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
