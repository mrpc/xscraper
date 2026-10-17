<?php
declare(strict_types=1);

namespace Mrpc\XScraper;

use Mrpc\XScraper\Cache\FileCache;
use Mrpc\XScraper\Cache\TtlPolicy;
use Mrpc\XScraper\Exception\AuthenticationException;
use Mrpc\XScraper\Exception\RateLimitException;
use Mrpc\XScraper\Exception\ResponseException;
use Mrpc\XScraper\Exception\TweetNotFoundException;
use Mrpc\XScraper\Http\BrowserProfile;
use Mrpc\XScraper\Http\CurlTransport;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\PramnosTransport;
use Mrpc\XScraper\Http\Transport;
use Mrpc\XScraper\Lock\FileLockFactory;
use Mrpc\XScraper\Lock\LockFactory;
use Mrpc\XScraper\Model\Conversation;
use Mrpc\XScraper\Model\FetchStats;
use Mrpc\XScraper\Model\Tweet;
use Mrpc\XScraper\RateLimit\Throttle;
use Pramnos\Http\Client as PramnosHttpClient;
use Psr\SimpleCache\CacheInterface;

/**
 * Main entry point for reading x.com posts and replies without the official API.
 *
 * Implements request frugality via incremental pagination, age-adaptive Snowflake TTLs,
 * cross-process single-flight locking, and a shared request throttle with 15-minute 429 cooldowns.
 */
class XScraper
{
    /** Public web client bearer token used by x.com. */
    public const DEFAULT_BEARER = 'AAAAAAAAAAAAAAAAAAAAANRILgAAAAAAnNwIzUejRCOuH5E6I8xnZz4puTs%3D1Zv7ttfk8LF81IUq16cHjhLTvJu4FA33AGWWjCpTnA';

    private Session $currentSession;

    public function __construct(
        Session $session,
        private ?Transport $transport = null,
        private ?CacheInterface $cache = null,
        private ?LockFactory $lockFactory = null,
        private ?Throttle $throttle = null,
        private ?TimelineParser $parser = null,
        private ?QueryIdResolver $queryIdResolver = null,
        private ?TtlPolicy $ttlPolicy = null,
        private ?BrowserProfile $browserProfile = null,
        private string $bearerToken = self::DEFAULT_BEARER,
    ) {
        $this->currentSession = $session;
        $this->parser ??= new TimelineParser();
        $this->queryIdResolver ??= new QueryIdResolver();
        $this->ttlPolicy ??= new TtlPolicy();
        $this->browserProfile ??= BrowserProfile::chromeLinux();

        // Defaults for cache, lock and throttle in temporary directory if not provided
        $baseDir = sys_get_temp_dir() . '/xscraper';
        $this->cache ??= new FileCache($baseDir . '/cache');
        $this->lockFactory ??= new FileLockFactory($baseDir . '/locks');
        $this->throttle ??= new Throttle($baseDir . '/throttle');

        if ($this->transport === null) {
            if (class_exists(PramnosHttpClient::class)) {
                $this->transport = new PramnosTransport();
            } else {
                $this->transport = new CurlTransport();
            }
        }
    }

    /**
     * Factory to instantiate with persistent file storage and automatic transport selection.
     */
    public static function create(
        Session $session,
        ?string $storageDir = null,
        ?string $proxy = null,
    ): self {
        $dir = $storageDir ?? (sys_get_temp_dir() . '/xscraper');
        $cache = new FileCache($dir . '/cache');
        $lockFactory = new FileLockFactory($dir . '/locks');
        $throttle = new Throttle($dir . '/throttle');

        $transport = class_exists(PramnosHttpClient::class)
            ? new PramnosTransport(proxy: $proxy)
            : new CurlTransport(proxy: $proxy);

        return new self(
            $session,
            transport: $transport,
            cache: $cache,
            lockFactory: $lockFactory,
            throttle: $throttle,
        );
    }

    public function session(): Session
    {
        return $this->currentSession;
    }

    /**
     * Fetch a focal post and its replies according to the given mode.
     *
     * @param string $postIdOrUrl post ID or URL (e.g. "https://x.com/user/status/123456789")
     * @param FetchMode $mode fetch strategy (Smart, CacheOnly, Refresh, Full)
     * @param int $maxReplies maximum replies to collect across paginated calls
     * @param float $lockTimeout seconds to wait for single-flight lock
     *
     * @throws TweetNotFoundException if tweet does not exist or is not in cache
     * @throws AuthenticationException on invalid session cookies
     * @throws RateLimitException on rate limiting or 429 cooldown
     */
    public function getConversation(
        string $postIdOrUrl,
        FetchMode $mode = FetchMode::Smart,
        int $maxReplies = 100,
        float $lockTimeout = 15.0,
    ): Conversation {
        $tweetId = Snowflake::parse($postIdOrUrl);
        $cacheKey = 'x_conv_' . $tweetId;

        // 1. Read existing cached conversation if available
        $cachedConv = $this->readCachedConversation($cacheKey);

        // 2. CacheOnly mode: never touch network
        if ($mode === FetchMode::CacheOnly) {
            if ($cachedConv === null) {
                throw new TweetNotFoundException($tweetId, "Post {$tweetId} is not available in cache");
            }
            return $cachedConv->withStats(new FetchStats(
                requests: 0,
                pages: 0,
                newReplies: 0,
                fromCache: true,
                coalesced: false,
                fetchedAt: $cachedConv->stats?->fetchedAt,
            ));
        }

        // 3. Smart mode freshness check
        if ($mode === FetchMode::Smart && $cachedConv !== null) {
            $lastFetch = $cachedConv->stats?->fetchedAt ?? 0;
            $ttl = $this->ttlPolicy->freshFor($tweetId, $lastFetch);
            $isFresh = ($lastFetch + $ttl) > time();

            // If fresh and we already have sufficient replies, return from cache immediately
            if ($isFresh && ($cachedConv->replyCount() >= $maxReplies || $cachedConv->replyCount() > 0)) {
                return $cachedConv->withStats(new FetchStats(
                    requests: 0,
                    pages: 0,
                    newReplies: 0,
                    fromCache: true,
                    coalesced: false,
                    fetchedAt: $lastFetch,
                ));
            }
        }

        // 4. Single-flight lock to avoid duplicate parallel scans across processes
        return $this->lockFactory->synchronized("fetch_{$tweetId}", $lockTimeout, function () use (
            $tweetId,
            $cacheKey,
            $mode,
            $maxReplies,
        ): Conversation {
            // Re-check cache inside lock: another worker may have just completed fetching it
            $cachedConv = $this->readCachedConversation($cacheKey);
            if ($mode === FetchMode::Smart && $cachedConv !== null) {
                $lastFetch = $cachedConv->stats?->fetchedAt ?? 0;
                $ttl = $this->ttlPolicy->freshFor($tweetId, $lastFetch);
                if (($lastFetch + $ttl) > time() && ($cachedConv->replyCount() >= $maxReplies || $cachedConv->replyCount() > 0)) {
                    return $cachedConv->withStats(new FetchStats(
                        requests: 0,
                        pages: 0,
                        newReplies: 0,
                        fromCache: true,
                        coalesced: true,
                        fetchedAt: $lastFetch,
                    ));
                }
            }

            return $this->performNetworkFetch($tweetId, $cacheKey, $mode, $maxReplies, $cachedConv);
        });
    }

    /**
     * Convenience method to fetch a single tweet without replies.
     */
    public function getTweet(string $postIdOrUrl, FetchMode $mode = FetchMode::Smart): Tweet
    {
        return $this->getConversation($postIdOrUrl, $mode, maxReplies: 0)->tweet;
    }

    /**
     * Executes the GraphQL network requests with incremental pagination and throttle management.
     */
    private function performNetworkFetch(
        string $tweetId,
        string $cacheKey,
        FetchMode $mode,
        int $maxReplies,
        ?Conversation $cachedConv,
    ): Conversation {
        $queryId = $this->queryIdResolver->resolve('TweetDetail');
        $features = $this->queryIdResolver->defaultFeatures('TweetDetail');
        $fieldToggles = $this->queryIdResolver->defaultFieldToggles('TweetDetail');

        $requestsCount = 0;
        $pagesCount = 0;
        $cursor = null;
        $focalTweet = null;
        /** @var array<string,Tweet> $newRepliesMap */
        $newRepliesMap = [];
        $stopIncremental = false;

        $existingReplyIds = [];
        if ($mode !== FetchMode::Full && $cachedConv !== null) {
            foreach ($cachedConv->replies as $r) {
                $existingReplyIds[$r->id] = true;
            }
        }

        while (true) {
            // Build variables
            $variables = [
                'focalTweetId' => $tweetId,
                'with_rux_injections' => false,
                'includePromotedContent' => false,
                'rankingMode' => 'Relevance',
            ];
            if ($cursor !== null) {
                $variables['cursor'] = $cursor;
            }

            $url = sprintf(
                'https://x.com/i/api/graphql/%s/TweetDetail?variables=%s&features=%s&fieldToggles=%s',
                $queryId,
                rawurlencode((string) json_encode($variables, JSON_UNESCAPED_SLASHES)),
                rawurlencode((string) json_encode($features, JSON_UNESCAPED_SLASHES)),
                rawurlencode((string) json_encode($fieldToggles, JSON_UNESCAPED_SLASHES)),
            );

            $referer = 'https://x.com/i/status/' . $tweetId;
            $headers = $this->browserProfile->apiHeaders(
                $this->bearerToken,
                $this->currentSession->ct0,
                $this->currentSession->authToken,
                $referer,
            );

            // Throttle pace & budget reservation
            $this->throttle->acquire($this->currentSession->key(), 'TweetDetail');

            $request = new HttpRequest('GET', $url, $headers);
            $requestsCount++;
            $pagesCount++;

            $response = $this->transport->send($request);

            // Record server limits and cooldowns
            $this->throttle->record(
                $this->currentSession->key(),
                'TweetDetail',
                $response->status,
                $response->headers,
            );

            // Handle ct0 rotation in response Set-Cookie headers
            $this->checkCookieRotation($response->header('set-cookie'));

            // Check HTTP status codes
            if ($response->status === 401 || $response->status === 403) {
                throw new AuthenticationException("X rejected session credentials (HTTP {$response->status})");
            }
            if ($response->status === 404) {
                throw new TweetNotFoundException($tweetId, "Post {$tweetId} not found or deleted (HTTP 404)");
            }
            if ($response->status === 429) {
                $resetAt = isset($response->headers['x-rate-limit-reset']) ? (int) $response->headers['x-rate-limit-reset'] : null;
                throw new RateLimitException("Rate limit reached on x.com (HTTP 429)", $resetAt);
            }
            if ($response->status < 200 || $response->status >= 400) {
                throw new ResponseException("Unexpected HTTP response {$response->status} from X GraphQL");
            }

            $json = $response->json();
            if ($json === null) {
                throw new ResponseException('Invalid JSON received from X GraphQL');
            }

            $parsed = $this->parser->parseTweetDetail($json, $tweetId);

            if ($focalTweet === null) {
                $focalTweet = $parsed->focalTweet;
            }

            // Check reply nodes in this page
            foreach ($parsed->replies as $reply) {
                // Incremental stop check: if reply was already known in cached replies, we reached the frontier
                if (!empty($existingReplyIds) && isset($existingReplyIds[$reply->id])) {
                    $stopIncremental = true;
                }
                $newRepliesMap[$reply->id] = $reply;
            }

            // Stop conditions
            if ($stopIncremental) {
                break;
            }

            $nextCursor = $parsed->bottomCursor;
            if ($nextCursor === null || $nextCursor === '' || $nextCursor === $cursor) {
                break;
            }

            if (count($newRepliesMap) >= $maxReplies) {
                break;
            }

            $cursor = $nextCursor;
        }

        if ($focalTweet === null) {
            if ($cachedConv !== null) {
                $focalTweet = $cachedConv->tweet;
            } else {
                throw new TweetNotFoundException($tweetId, "Could not find post {$tweetId} in X response");
            }
        }

        // Merge replies: in Full mode only newly fetched; in Smart/Refresh merge with cached
        $finalReplies = [];
        if ($mode === FetchMode::Full || $cachedConv === null) {
            $finalReplies = array_values($newRepliesMap);
        } else {
            $merged = [];
            foreach ($cachedConv->replies as $r) {
                $merged[$r->id] = $r;
            }
            foreach ($newRepliesMap as $id => $r) {
                $merged[$id] = $r;
            }
            $finalReplies = array_values($merged);
        }

        $now = time();
        $stats = new FetchStats(
            requests: $requestsCount,
            pages: $pagesCount,
            newReplies: count($newRepliesMap),
            fromCache: false,
            coalesced: false,
            fetchedAt: $now,
        );

        $conversation = new Conversation($focalTweet, $finalReplies, $stats);

        // Save updated conversation to cache with age-adaptive TTL
        $ttl = $this->ttlPolicy->freshFor($tweetId, $now);
        $this->cache->set($cacheKey, $conversation->toArray(), $ttl);

        return $conversation;
    }

    private function readCachedConversation(string $cacheKey): ?Conversation
    {
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['tweet'])) {
            try {
                return Conversation::fromArray($cached);
            } catch (\Throwable) {
                // Corrupted cache entry; treat as miss
                return null;
            }
        }
        return null;
    }

    private function checkCookieRotation(string $setCookieHeader): void
    {
        if ($setCookieHeader === '') {
            return;
        }

        if (preg_match('/(?:^|;|,)\s*ct0=([^;\s,]+)/', $setCookieHeader, $m)) {
            $newCt0 = trim($m[1]);
            if ($newCt0 !== '' && $newCt0 !== $this->currentSession->ct0) {
                $this->currentSession = new Session(
                    $this->currentSession->authToken,
                    $newCt0,
                    $this->currentSession->name,
                );
            }
        }
    }
}
