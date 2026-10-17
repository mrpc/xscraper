<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use Mrpc\XScraper\TimelineParser;
use PHPUnit\Framework\TestCase;

final class TimelineParserTest extends TestCase
{
    private function sampleGraphqlResponse(string $focalId): array
    {
        return [
            'data' => [
                'threaded_conversation_with_injections_v2' => [
                    'instructions' => [
                        [
                            'type' => 'TimelineAddEntries',
                            'entries' => [
                                [
                                    'entryId' => "tweet-{$focalId}",
                                    'content' => [
                                        'entryType' => 'TimelineTimelineItem',
                                        'itemContent' => [
                                            'itemType' => 'TimelineTweet',
                                            'tweet_results' => [
                                                'result' => [
                                                    '__typename' => 'TweetWithVisibilityResults',
                                                    'tweet' => [
                                                        'rest_id' => $focalId,
                                                        'core' => [
                                                            'user_results' => [
                                                                'result' => [
                                                                    'rest_id' => 'user_1',
                                                                    'legacy' => [
                                                                        'screen_name' => 'focal_user',
                                                                        'name' => 'Focal User',
                                                                        'verified' => true,
                                                                        'followers_count' => 15000,
                                                                        'profile_image_url_https' => 'https://pbs.twimg.com/avatar.jpg',
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                        'legacy' => [
                                                            'conversation_id_str' => $focalId,
                                                            'created_at' => 'Sat Oct 17 12:00:00 +0000 2026',
                                                            'full_text' => 'Focal post with #cool link https://t.co/xyz @someone',
                                                            'lang' => 'en',
                                                            'favorite_count' => 120,
                                                            'retweet_count' => 30,
                                                            'reply_count' => 5,
                                                            'quote_count' => 2,
                                                            'bookmark_count' => 10,
                                                            'quoted_status_id_str' => '999888',
                                                            'entities' => [
                                                                'urls' => [['expanded_url' => 'https://example.com/cool']],
                                                                'hashtags' => [['text' => 'cool']],
                                                                'user_mentions' => [['screen_name' => 'someone']],
                                                            ],
                                                            'extended_entities' => [
                                                                'media' => [
                                                                    [
                                                                        'type' => 'video',
                                                                        'media_url_https' => 'https://pbs.twimg.com/thumb.jpg',
                                                                        'ext_alt_text' => 'A video alt text',
                                                                        'video_info' => [
                                                                            'variants' => [
                                                                                ['bitrate' => 256000, 'content_type' => 'video/mp4', 'url' => 'https://video.twimg.com/low.mp4'],
                                                                                ['bitrate' => 832000, 'content_type' => 'video/mp4', 'url' => 'https://video.twimg.com/high.mp4'],
                                                                                ['content_type' => 'application/x-mpegURL', 'url' => 'https://video.twimg.com/stream.m3u8'],
                                                                            ],
                                                                        ],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                        'views' => ['count' => '45000'],
                                                        'note_tweet' => [
                                                            'note_tweet_results' => [
                                                                'result' => [
                                                                    'text' => 'Full longform text overriding truncated full_text',
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'entryId' => 'conversationthread-1',
                                    'content' => [
                                        'entryType' => 'TimelineTimelineModule',
                                        'items' => [
                                            [
                                                'entryId' => 'conversationthread-1-tweet-201',
                                                'item' => [
                                                    'itemContent' => [
                                                        'itemType' => 'TimelineTweet',
                                                        'tweet_results' => [
                                                            'result' => [
                                                                '__typename' => 'Tweet',
                                                                'rest_id' => '201',
                                                                'core' => [
                                                                    'user_results' => [
                                                                        'result' => [
                                                                            'rest_id' => 'user_2',
                                                                            'legacy' => [
                                                                                'screen_name' => 'reply_user',
                                                                                'name' => 'Replier',
                                                                                'verified' => false,
                                                                                'followers_count' => 100,
                                                                            ],
                                                                        ],
                                                                    ],
                                                                ],
                                                                'legacy' => [
                                                                    'conversation_id_str' => $focalId,
                                                                    'in_reply_to_status_id_str' => $focalId,
                                                                    'created_at' => 'Sat Oct 17 12:05:00 +0000 2026',
                                                                    'full_text' => 'Direct reply text',
                                                                    'favorite_count' => 3,
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                            [
                                                'entryId' => 'conversationthread-1-tweet-tombstone',
                                                'item' => [
                                                    'itemContent' => [
                                                        'itemType' => 'TimelineTweet',
                                                        'tweet_results' => [
                                                            'result' => [
                                                                '__typename' => 'TweetTombstone',
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'entryId' => 'cursor-bottom-12345',
                                    'content' => [
                                        'entryType' => 'TimelineTimelineCursor',
                                        'cursorType' => 'Bottom',
                                        'value' => 'next_cursor_token_abc',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testParseTweetDetailComplete(): void
    {
        $parser = new TimelineParser();
        $focalId = '1845187747970863384';
        $json = $this->sampleGraphqlResponse($focalId);

        $result = $parser->parseTweetDetail($json, $focalId);

        // Focal tweet
        $this->assertNotNull($result->focalTweet);
        $this->assertSame($focalId, $result->focalTweet->id);
        $this->assertSame('Full longform text overriding truncated full_text', $result->focalTweet->text);
        $this->assertSame('focal_user', $result->focalTweet->user->screenName);
        $this->assertTrue($result->focalTweet->user->verified);
        $this->assertSame(45000, $result->focalTweet->views);
        $this->assertSame('999888', $result->focalTweet->quotedId);

        // Media
        $this->assertCount(1, $result->focalTweet->media);
        $media = $result->focalTweet->media[0];
        $this->assertSame('video', $media->type);
        $this->assertSame('https://video.twimg.com/high.mp4', $media->url); // Selected highest bitrate
        $this->assertSame('A video alt text', $media->altText);

        // Entities
        $this->assertSame(['https://example.com/cool'], $result->focalTweet->urls);
        $this->assertSame(['cool'], $result->focalTweet->hashtags);
        $this->assertSame(['someone'], $result->focalTweet->mentions);

        // Replies
        $this->assertCount(1, $result->replies);
        $reply = $result->replies[0];
        $this->assertSame('201', $reply->id);
        $this->assertSame('Direct reply text', $reply->text);
        $this->assertTrue($reply->isReplyTo($focalId));

        // Cursor
        $this->assertSame('next_cursor_token_abc', $result->bottomCursor);
    }

    public function testParseEmptyOrMalformed(): void
    {
        $parser = new TimelineParser();
        $res = $parser->parseTweetDetail([], '123');

        $this->assertNull($res->focalTweet);
        $this->assertEmpty($res->replies);
        $this->assertNull($res->bottomCursor);
    }

    public function testParseStandaloneTweetResult(): void
    {
        $parser = new TimelineParser();
        $json = [
            'data' => [
                'tweetResult' => [
                    'result' => [
                        '__typename' => 'Tweet',
                        'rest_id' => '555',
                        'legacy' => [
                            'full_text' => 'Standalone tweet',
                            'created_at' => 'Sat Oct 17 12:00:00 +0000 2026',
                        ],
                    ],
                ],
            ],
        ];

        $res = $parser->parseTweetDetail($json, '555');
        $this->assertNotNull($res->focalTweet);
        $this->assertSame('555', $res->focalTweet->id);
    }

    public function testParseTimelineAddToModuleAndTopCursor(): void
    {
        $parser = new TimelineParser();
        $json = [
            'data' => [
                'threaded_conversation_with_injections_v2' => [
                    'instructions' => [
                        [
                            'type' => 'TimelineAddToModule',
                            'moduleItems' => [
                                [
                                    'itemContent' => [
                                        'itemType' => 'TimelineTweet',
                                        'tweet_results' => [
                                            'result' => [
                                                '__typename' => 'Tweet',
                                                'rest_id' => '301',
                                                'legacy' => [
                                                    'full_text' => 'Module tweet',
                                                    'created_at' => 'Sat Oct 17 12:00:00 +0000 2026',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'TimelineAddEntries',
                            'entries' => [
                                [
                                    'entryId' => 'cursor-top-1234',
                                    'content' => [
                                        'entryType' => 'TimelineTimelineCursor',
                                        'cursorType' => 'Top',
                                        'value' => 'top_cursor_val',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $parser->parseTweetDetail($json, '999');
        $this->assertCount(1, $res->replies);
        $this->assertSame('301', $res->replies[0]->id);
        $this->assertSame('top_cursor_val', $res->topCursor);
    }

    public function testParsePhotoMediaAndQuotedResult(): void
    {
        $parser = new TimelineParser();
        $node = [
            '__typename' => 'Tweet',
            'rest_id' => '400',
            'quoted_status_result' => [
                'result' => [
                    'rest_id' => 'quoted_rest_id_123',
                ],
            ],
            'legacy' => [
                'full_text' => 'Photo post',
                'created_at' => '2026-10-17T12:00:00Z', // ISO date fallback
                'entities' => [
                    'media' => [
                        [
                            'type' => 'photo',
                            'media_url_https' => 'https://pbs.twimg.com/pic.jpg',
                            'ext_alt_text' => 'Alt text',
                        ],
                    ],
                ],
            ],
        ];

        $tweet = $parser->parseTweetNode($node);
        $this->assertNotNull($tweet);
        $this->assertSame('400', $tweet->id);
        $this->assertSame('quoted_rest_id_123', $tweet->quotedId);
        $this->assertCount(1, $tweet->media);
        $this->assertSame('photo', $tweet->media[0]->type);
        $this->assertSame('https://pbs.twimg.com/pic.jpg', $tweet->media[0]->url);
        $this->assertNotNull($tweet->createdAt);
    }

    public function testParseInvalidDateReturnsNull(): void
    {
        $parser = new TimelineParser();
        $node = [
            '__typename' => 'Tweet',
            'rest_id' => '401',
            'legacy' => [
                'full_text' => 'Bad date',
                'created_at' => 'not-a-date-at-all-nonsense',
            ],
        ];

        $tweet = $parser->parseTweetNode($node);
        $this->assertNotNull($tweet);
        $this->assertNull($tweet->createdAt);
    }

    public function testParseModuleCursorAndItemCursor(): void
    {
        $parser = new TimelineParser();
        $json = [
            'data' => [
                'threaded_conversation_with_injections_v2' => [
                    'instructions' => [
                        [
                            'type' => 'TimelineAddEntries',
                            'entries' => [
                                [
                                    'entryId' => 'item-cursor-entry',
                                    'content' => [
                                        'entryType' => 'TimelineTimelineItem',
                                        'itemContent' => [
                                            'itemType' => 'TimelineTimelineCursor',
                                            'cursorType' => 'Top',
                                            'value' => 'item_top_cursor',
                                        ],
                                    ],
                                ],
                                [
                                    'entryId' => 'module-cursor-entry',
                                    'content' => [
                                        'entryType' => 'TimelineTimelineModule',
                                        'items' => [
                                            [
                                                'item' => [
                                                    'itemContent' => [
                                                        'itemType' => 'TimelineTimelineCursor',
                                                        'cursorType' => 'Bottom',
                                                        'value' => 'module_bottom_cursor',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $parser->parseTweetDetail($json, '111');
        $this->assertSame('item_top_cursor', $res->topCursor);
        $this->assertSame('module_bottom_cursor', $res->bottomCursor);
    }

    public function testParseAnimatedGifMedia(): void
    {
        $parser = new TimelineParser();
        $node = [
            '__typename' => 'Tweet',
            'rest_id' => '501',
            'legacy' => [
                'full_text' => 'GIF tweet',
                'extended_entities' => [
                    'media' => [
                        [
                            'type' => 'animated_gif',
                            'media_url_https' => 'https://pbs.twimg.com/thumb.gif',
                            'video_info' => [
                                'variants' => [
                                    ['content_type' => 'video/mp4', 'bitrate' => 0, 'url' => 'https://video.twimg.com/gif.mp4'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $tweet = $parser->parseTweetNode($node);
        $this->assertNotNull($tweet);
        $this->assertCount(1, $tweet->media);
        $this->assertSame('animated_gif', $tweet->media[0]->type);
        $this->assertSame('https://video.twimg.com/gif.mp4', $tweet->media[0]->url);
    }
}
