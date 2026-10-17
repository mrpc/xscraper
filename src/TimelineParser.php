<?php
declare(strict_types=1);

namespace Mrpc\XScraper;

use DateTimeImmutable;
use Mrpc\XScraper\Model\Media;
use Mrpc\XScraper\Model\TimelineResult;
use Mrpc\XScraper\Model\Tweet;
use Mrpc\XScraper\Model\User;

/**
 * Parses X.com GraphQL timeline responses (specifically TweetDetail).
 *
 * Extracts the focal tweet, replies, and pagination cursors. Handles TweetWithVisibilityResults,
 * longform note tweets, rich media variants, and module item trees.
 */
final class TimelineParser
{
    /**
     * @param array<string,mixed> $json
     */
    public function parseTweetDetail(array $json, string $focalTweetId): TimelineResult
    {
        $instructions = $this->extractInstructions($json);

        $focalTweet = null;
        $replies = [];
        $bottomCursor = null;
        $topCursor = null;

        foreach ($instructions as $instruction) {
            $type = $instruction['type'] ?? '';

            if ($type === 'TimelineAddEntries') {
                $entries = $instruction['entries'] ?? [];
                foreach ($entries as $entry) {
                    $this->processEntry($entry, $focalTweetId, $focalTweet, $replies, $bottomCursor, $topCursor);
                }
            } elseif ($type === 'TimelineAddToModule') {
                $moduleItems = $instruction['moduleItems'] ?? [];
                foreach ($moduleItems as $item) {
                    $tweet = $this->extractTweetFromItem($item);
                    if ($tweet !== null) {
                        if ($tweet->id === $focalTweetId) {
                            $focalTweet ??= $tweet;
                        } else {
                            $replies[$tweet->id] = $tweet;
                        }
                    }
                }
            }
        }

        // If focal tweet was not inside instructions (e.g. standalone tweetResult endpoint)
        if ($focalTweet === null && isset($json['data']['tweetResult']['result'])) {
            $focalTweet = $this->parseTweetNode($json['data']['tweetResult']['result']);
        }

        return new TimelineResult(
            $focalTweet,
            array_values($replies),
            $bottomCursor,
            $topCursor,
        );
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,Tweet> $replies
     */
    private function processEntry(
        array $entry,
        string $focalTweetId,
        ?Tweet &$focalTweet,
        array &$replies,
        ?string &$bottomCursor,
        ?string &$topCursor,
    ): void {
        $entryId = (string) ($entry['entryId'] ?? '');
        $content = $entry['content'] ?? [];
        $entryType = $content['entryType'] ?? '';

        // Check for direct cursor entry
        if ($entryType === 'TimelineTimelineCursor' || ($entryType !== 'TimelineTimelineModule' && str_contains($entryId, 'cursor-'))) {
            $itemContent = $content['itemContent'] ?? [];
            $cursorType = $content['cursorType'] ?? $itemContent['cursorType'] ?? '';
            $value = (string) ($content['value'] ?? $itemContent['value'] ?? '');
            if ($value !== '') {
                if ($cursorType === 'Bottom' || str_contains($entryId, 'cursor-bottom')) {
                    $bottomCursor = $value;
                } elseif ($cursorType === 'Top' || str_contains($entryId, 'cursor-top')) {
                    $topCursor = $value;
                }
            }
            return;
        }

        // Single item
        if ($entryType === 'TimelineTimelineItem') {
            $tweet = $this->extractTweetFromItem($content);
            if ($tweet !== null) {
                if ($tweet->id === $focalTweetId) {
                    $focalTweet ??= $tweet;
                } else {
                    $replies[$tweet->id] = $tweet;
                }
            }

            // Check if item was a cursor item
            $itemContent = $content['itemContent'] ?? [];
            if (($itemContent['itemType'] ?? '') === 'TimelineTimelineCursor') {
                $cursorType = $itemContent['cursorType'] ?? '';
                $value = (string) ($itemContent['value'] ?? '');
                if ($value !== '') {
                    if ($cursorType === 'Bottom' || str_contains($entryId, 'cursor-bottom')) {
                        $bottomCursor = $value;
                    } elseif ($cursorType === 'Top' || str_contains($entryId, 'cursor-top')) {
                        $topCursor = $value;
                    }
                }
            }
            return;
        }

        // Thread / module containing multiple reply items
        if ($entryType === 'TimelineTimelineModule') {
            $items = $content['items'] ?? [];
            foreach ($items as $itemWrapper) {
                $subItem = $itemWrapper['item'] ?? $itemWrapper;
                $tweet = $this->extractTweetFromItem($subItem);
                if ($tweet !== null) {
                    if ($tweet->id === $focalTweetId) {
                        $focalTweet ??= $tweet;
                    } else {
                        $replies[$tweet->id] = $tweet;
                    }
                }

                // Check for module cursor
                $itemContent = $subItem['itemContent'] ?? $subItem['content'] ?? $subItem;
                $itemType = $itemContent['itemType'] ?? $itemContent['entryType'] ?? '';
                if ($itemType === 'TimelineTimelineCursor' || isset($itemContent['cursorType'])) {
                    $cursorType = $itemContent['cursorType'] ?? '';
                    $value = (string) ($itemContent['value'] ?? '');
                    if ($value !== '') {
                        if ($cursorType === 'Bottom' || str_contains((string) ($itemWrapper['entryId'] ?? ''), 'cursor-bottom')) {
                            $bottomCursor = $value;
                        } elseif ($cursorType === 'Top' || str_contains((string) ($itemWrapper['entryId'] ?? ''), 'cursor-top')) {
                            $topCursor = $value;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $item
     */
    private function extractTweetFromItem(array $item): ?Tweet
    {
        $itemContent = $item['itemContent'] ?? $item;
        $itemType = $itemContent['itemType'] ?? '';

        if ($itemType !== '' && $itemType !== 'TimelineTweet') {
            return null;
        }

        $result = $itemContent['tweet_results']['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        return $this->parseTweetNode($result);
    }

    /**
     * @param array<string,mixed> $node
     */
    public function parseTweetNode(array $node): ?Tweet
    {
        $typeName = $node['__typename'] ?? '';
        if ($typeName === 'TweetWithVisibilityResults') {
            $node = $node['tweet'] ?? [];
        }

        if (empty($node) || ($node['__typename'] ?? '') === 'TweetTombstone' || ($node['__typename'] ?? '') === 'TweetUnavailable') {
            return null;
        }

        $id = (string) ($node['rest_id'] ?? '');
        if ($id === '') {
            return null;
        }

        $legacy = $node['legacy'] ?? [];
        $conversationId = (string) ($legacy['conversation_id_str'] ?? $id);
        $inReplyToId = isset($legacy['in_reply_to_status_id_str']) ? (string) $legacy['in_reply_to_status_id_str'] : null;

        // Longform note tweet text if available, otherwise full_text
        $noteText = $node['note_tweet']['note_tweet_results']['result']['text'] ?? null;
        $text = is_string($noteText) && $noteText !== '' ? $noteText : (string) ($legacy['full_text'] ?? '');

        $lang = $legacy['lang'] ?? null;
        $createdAt = $this->parseDate($legacy['created_at'] ?? null);

        $likes = (int) ($legacy['favorite_count'] ?? 0);
        $retweets = (int) ($legacy['retweet_count'] ?? 0);
        $replyCount = (int) ($legacy['reply_count'] ?? 0);
        $quotes = (int) ($legacy['quote_count'] ?? 0);
        $bookmarks = (int) ($legacy['bookmark_count'] ?? 0);

        $views = null;
        if (isset($node['views']['count'])) {
            $views = is_numeric($node['views']['count']) ? (int) $node['views']['count'] : null;
        }

        $user = $this->parseUser($node['core']['user_results']['result'] ?? []);
        $media = $this->parseMedia($legacy['extended_entities']['media'] ?? $legacy['entities']['media'] ?? []);

        $urls = [];
        foreach ($legacy['entities']['urls'] ?? [] as $u) {
            if (isset($u['expanded_url']) && is_string($u['expanded_url'])) {
                $urls[] = $u['expanded_url'];
            }
        }

        $hashtags = [];
        foreach ($legacy['entities']['hashtags'] ?? [] as $h) {
            if (isset($h['text']) && is_string($h['text'])) {
                $hashtags[] = $h['text'];
            }
        }

        $mentions = [];
        foreach ($legacy['entities']['user_mentions'] ?? [] as $m) {
            if (isset($m['screen_name']) && is_string($m['screen_name'])) {
                $mentions[] = $m['screen_name'];
            }
        }

        $quotedId = isset($legacy['quoted_status_id_str'])
            ? (string) $legacy['quoted_status_id_str']
            : ($node['quoted_status_result']['result']['rest_id'] ?? null);

        return new Tweet(
            $id,
            $conversationId,
            $inReplyToId,
            $createdAt,
            $text,
            $lang,
            $user,
            $likes,
            $retweets,
            $replyCount,
            $quotes,
            $bookmarks,
            $views,
            $media,
            $urls,
            $hashtags,
            $mentions,
            $quotedId !== null ? (string) $quotedId : null,
        );
    }

    /**
     * @param array<string,mixed> $u
     */
    private function parseUser(array $u): User
    {
        $id = (string) ($u['rest_id'] ?? '');
        $legacy = $u['legacy'] ?? [];

        $screenName = $legacy['screen_name'] ?? null;
        $name = $legacy['name'] ?? null;
        $verified = (bool) ($legacy['verified'] ?? $u['is_blue_verified'] ?? false);
        $followers = isset($legacy['followers_count']) ? (int) $legacy['followers_count'] : null;
        $avatarUrl = $legacy['profile_image_url_https'] ?? null;

        return new User(
            $id,
            $screenName !== null ? (string) $screenName : null,
            $name !== null ? (string) $name : null,
            $verified,
            $followers,
            $avatarUrl !== null ? (string) $avatarUrl : null,
        );
    }

    /**
     * @param list<array<string,mixed>> $mediaList
     * @return list<Media>
     */
    private function parseMedia(array $mediaList): array
    {
        $out = [];
        foreach ($mediaList as $m) {
            $type = (string) ($m['type'] ?? 'photo');
            $previewUrl = (string) ($m['media_url_https'] ?? '');
            $altText = isset($m['ext_alt_text']) ? (string) $m['ext_alt_text'] : null;
            $url = $previewUrl;

            if (($type === 'video' || $type === 'animated_gif') && isset($m['video_info']['variants'])) {
                $bestBitrate = -1;
                $bestUrl = null;
                foreach ($m['video_info']['variants'] as $v) {
                    if (($v['content_type'] ?? '') === 'video/mp4') {
                        $bitrate = (int) ($v['bitrate'] ?? 0);
                        if ($bitrate > $bestBitrate) {
                            $bestBitrate = $bitrate;
                            $bestUrl = (string) ($v['url'] ?? '');
                        }
                    }
                }
                if ($bestUrl !== null && $bestUrl !== '') {
                    $url = $bestUrl;
                }
            }

            if ($url !== '') {
                $out[] = new Media($type, $url, $previewUrl !== '' ? $previewUrl : null, $altText);
            }
        }
        return $out;
    }

    private function parseDate(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('D M d H:i:s O Y', $raw);
        if ($dt !== false) {
            return $dt;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $json
     * @return list<array<string,mixed>>
     */
    private function extractInstructions(array $json): array
    {
        $instructions = $json['data']['threaded_conversation_with_injections_v2']['instructions'] ?? null;
        if (is_array($instructions)) {
            return $instructions;
        }

        $instructions = $json['data']['tweetResult']['result']['timelineResponse']['instructions'] ?? null;
        if (is_array($instructions)) {
            return $instructions;
        }

        return [];
    }
}
