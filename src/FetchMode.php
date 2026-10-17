<?php
declare(strict_types=1);

namespace Mrpc\XScraper;

/** How a call may use the cache and the network. */
enum FetchMode: string
{
    /**
     * Default. Serve from cache while fresh; when stale, fetch only the newest page(s)
     * until a known reply is reached; go deeper only if more replies are requested.
     */
    case Smart = 'smart';

    /** Never touch the network. Returns whatever is stored (possibly empty). */
    case CacheOnly = 'cache';

    /** Ignore freshness and do an incremental head refresh now (still merges with the store). */
    case Refresh = 'refresh';

    /** Discard the stored conversation and rebuild it from scratch. Most expensive. */
    case Full = 'full';
}
