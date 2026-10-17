<?php
declare(strict_types=1);

namespace Mrpc\XScraper;

use Mrpc\XScraper\Exception\QueryIdException;
use Mrpc\XScraper\Exception\TransportException;
use Mrpc\XScraper\Http\BrowserProfile;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\Transport;
use Psr\SimpleCache\CacheInterface;

/**
 * Resolves GraphQL operation query IDs and provides standard feature maps.
 *
 * Query IDs rotate when X redeploys its web client. They can be overridden via constructor,
 * environment variables, or discovered dynamically from client script bundles.
 */
final class QueryIdResolver
{
    /** Known stable TweetDetail query ID fallback. */
    public const DEFAULT_TWEET_DETAIL_QUERY_ID = 'V9vuycs4vg0BIiXq6P0d4A';

    /** @var array<string,string> */
    private array $queryIds;

    /**
     * @param array<string,string> $overrides
     */
    public function __construct(array $overrides = [])
    {
        $this->queryIds = [
            'TweetDetail' => self::DEFAULT_TWEET_DETAIL_QUERY_ID,
            ...$overrides,
        ];

        // Check environment variable overrides
        $envTweetDetail = getenv('X_QUERY_ID_TWEET_DETAIL');
        if (is_string($envTweetDetail) && $envTweetDetail !== '') {
            $this->queryIds['TweetDetail'] = $envTweetDetail;
        }

        $envAll = getenv('X_QUERY_IDS');
        if (is_string($envAll) && $envAll !== '') {
            $parsed = json_decode($envAll, true);
            if (is_array($parsed)) {
                foreach ($parsed as $op => $id) {
                    if (is_string($op) && is_string($id) && $id !== '') {
                        $this->queryIds[$op] = $id;
                    }
                }
            }
        }
    }

    public function set(string $operation, string $queryId): void
    {
        if ($queryId === '') {
            throw new \InvalidArgumentException('Query ID must not be empty');
        }
        $this->queryIds[$operation] = $queryId;
    }

    /**
     * Get the query ID for an operation.
     *
     * @throws QueryIdException if no query ID is available
     */
    public function resolve(string $operation): string
    {
        $id = $this->queryIds[$operation] ?? null;
        if ($id === null || $id === '') {
            throw new QueryIdException("No query ID configured for GraphQL operation '{$operation}'");
        }
        return $id;
    }

    /**
     * Features boolean flags sent with GraphQL queries on X.
     *
     * @return array<string,bool>
     */
    public function defaultFeatures(string $operation = 'TweetDetail'): array
    {
        return [
            'responsive_web_graphql_exclude_directive_enabled' => true,
            'verified_phone_label_enabled' => false,
            'responsive_web_home_pinned_timelines_enabled' => true,
            'creator_subscriptions_tweet_preview_api_enabled' => true,
            'responsive_web_graphql_timeline_navigation_enabled' => true,
            'responsive_web_graphql_skip_user_profile_image_extensions_enabled' => false,
            'c9s_tweet_anatomy_moderator_badge_enabled' => true,
            'tweetypie_unmention_optimization_enabled' => true,
            'responsive_web_edit_tweet_api_enabled' => true,
            'graphql_is_translatable_rweb_tweet_is_translatable_enabled' => true,
            'view_counts_everywhere_api_enabled' => true,
            'longform_notetweets_consumption_enabled' => true,
            'responsive_web_twitter_article_tweet_consumption_enabled' => true,
            'tweet_awards_web_tipping_enabled' => false,
            'freedom_of_speech_not_effort_enabled' => true,
            'standardized_nudges_misinfo' => true,
            'tweet_with_visibility_results_prefer_gql_limited_actions_policy_enabled' => true,
            'rweb_video_timestamps_enabled' => true,
            'longform_notetweets_rich_text_read_enabled' => true,
            'longform_notetweets_inline_media_enabled' => true,
            'responsive_web_enhance_cards_enabled' => false,
        ];
    }

    /**
     * Field toggles sent with GraphQL queries on X.
     *
     * @return array<string,bool>
     */
    public function defaultFieldToggles(string $operation = 'TweetDetail'): array
    {
        return [
            'withArticleRichContentState' => true,
            'withArticlePlainText' => false,
            'withGrokAnalyze' => false,
            'withDisallowedReplyControls' => false,
        ];
    }

    /**
     * Discover a query ID from X client script bundles.
     *
     * @throws QueryIdException on failure
     */
    public function discover(
        string $operation,
        Transport $transport,
        BrowserProfile $profile,
        Session $session,
        ?CacheInterface $cache = null,
    ): string {
        $cacheKey = 'xscraper_queryid_' . $operation;
        if ($cache !== null) {
            $cached = $cache->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $this->queryIds[$operation] = $cached;
                return $cached;
            }
        }

        try {
            // Step 1: fetch x.com main page to find script URLs
            $req = new HttpRequest('GET', 'https://x.com/', $profile->documentHeaders($session->ct0, $session->authToken));
            $res = $transport->send($req);
            if ($res->status < 200 || $res->status >= 400) {
                throw new QueryIdException("Failed to fetch x.com document: HTTP {$res->status}");
            }

            // Find JS bundle script tags: <script src="https://abs.twimg.com/responsive-web/client-web/...main....js">
            preg_match_all('~https://abs\.twimg\.com/responsive-web/client-web/[A-Za-z0-9_.-]+\.js~', $res->body, $matches);
            $scriptUrls = array_unique($matches[0] ?? []);

            // If none found, also look for relative script tags
            if (empty($scriptUrls)) {
                preg_match_all('~/responsive-web/client-web/[A-Za-z0-9_.-]+\.js~', $res->body, $relMatches);
                foreach ($relMatches[0] ?? [] as $rel) {
                    $scriptUrls[] = 'https://abs.twimg.com' . $rel;
                }
            }

            foreach ($scriptUrls as $scriptUrl) {
                $scriptReq = new HttpRequest('GET', $scriptUrl, $profile->documentHeaders($session->ct0, $session->authToken, script: true));
                $scriptRes = $transport->send($scriptReq);
                if ($scriptRes->status !== 200) {
                    continue;
                }

                $found = $this->extractQueryIdFromScript($scriptRes->body, $operation);
                if ($found !== null) {
                    $this->queryIds[$operation] = $found;
                    $cache?->set($cacheKey, $found, 86400);
                    return $found;
                }
            }
        } catch (TransportException $e) {
            throw new QueryIdException("Network error while discovering query ID for {$operation}: {$e->getMessage()}", 0, $e);
        }

        throw new QueryIdException("Could not discover query ID for {$operation} from client bundles");
    }

    /**
     * Extracts query ID from JS bundle text.
     */
    public function extractQueryIdFromScript(string $js, string $operation): ?string
    {
        // Pattern 1: {queryId:"V9vuycs4vg0BIiXq6P0d4A",operationName:"TweetDetail",operationType:"query"}
        if (preg_match('~\{queryId:\s*"([A-Za-z0-9_-]{10,40})",\s*operationName:\s*"' . preg_quote($operation, '~') . '"~', $js, $m)) {
            return $m[1];
        }

        // Pattern 2: {operationName:"TweetDetail",queryId:"V9vuycs4vg0BIiXq6P0d4A"}
        if (preg_match('~operationName:\s*"' . preg_quote($operation, '~') . '",\s*queryId:\s*"([A-Za-z0-9_-]{10,40})"~', $js, $m)) {
            return $m[1];
        }

        // Pattern 3: e.id = "V9vuycs4vg0BIiXq6P0d4A", e.name = "TweetDetail"
        if (preg_match('~id:\s*"([A-Za-z0-9_-]{10,40})",\s*name:\s*"' . preg_quote($operation, '~') . '"~', $js, $m)) {
            return $m[1];
        }

        return null;
    }
}
