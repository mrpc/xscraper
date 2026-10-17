<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use DateTimeImmutable;
use Mrpc\XScraper\Model\Conversation;
use Mrpc\XScraper\Model\FetchStats;
use Mrpc\XScraper\Model\Media;
use Mrpc\XScraper\Model\TimelineResult;
use Mrpc\XScraper\Model\Tweet;
use Mrpc\XScraper\Model\User;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    /**
     * Verifies User model creation, serialization, deserialization, and URL generation.
     */
    public function testUserModel(): void
    {
        // Arrange
        $user = new User(
            id: '123',
            screenName: 'mrpc',
            name: 'Pramnos',
            verified: true,
            followers: 1000,
            avatarUrl: 'https://pbs.twimg.com/avatar.jpg',
        );

        // Act & Assert (profileUrl and toArray)
        $this->assertSame('https://x.com/mrpc', $user->profileUrl());
        $array = $user->toArray();
        $this->assertSame('mrpc', $array['screen_name']);

        // Act & Assert (fromArray restoration)
        $restored = User::fromArray($array);
        $this->assertSame($user->id, $restored->id);
        $this->assertSame($user->screenName, $restored->screenName);
        $this->assertTrue($restored->verified);

        // Act & Assert (anonymous user profile URL)
        $anonUser = new User('456', null, null);
        $this->assertNull($anonUser->profileUrl());
    }

    /**
     * Verifies Media model properties, serialization, and deserialization.
     */
    public function testMediaModel(): void
    {
        // Arrange
        $media = new Media(
            type: 'video',
            url: 'https://video.twimg.com/vid.mp4',
            previewUrl: 'https://pbs.twimg.com/thumb.jpg',
            altText: 'A clip',
        );

        // Act
        $array = $media->toArray();
        $restored = Media::fromArray($array);

        // Assert
        $this->assertSame('video', $array['type']);
        $this->assertSame($media->url, $restored->url);
        $this->assertSame($media->altText, $restored->altText);
    }

    /**
     * Verifies Tweet model relationships, URLs, serialization, and deserialization.
     */
    public function testTweetModel(): void
    {
        // Arrange
        $user = new User('10', 'mrpc', 'Pramnos');
        $tweet = new Tweet(
            id: '100',
            conversationId: '100',
            inReplyToId: null,
            createdAt: new DateTimeImmutable('2026-10-17 12:00:00+00:00'),
            text: 'Hello world! #php @x',
            lang: 'en',
            user: $user,
            likes: 42,
            retweets: 5,
            replies: 2,
            quotes: 1,
            bookmarks: 3,
            views: 500,
            media: [new Media('photo', 'https://img.jpg')],
            urls: ['https://example.com'],
            hashtags: ['php'],
            mentions: ['x'],
            quotedId: '99',
        );

        // Act & Assert (root tweet properties)
        $this->assertSame('https://x.com/mrpc/status/100', $tweet->url());
        $this->assertFalse($tweet->isReplyTo('100'));

        // Arrange (reply tweet)
        $replyTweet = new Tweet(
            id: '101',
            conversationId: '100',
            inReplyToId: '100',
            createdAt: new DateTimeImmutable('2026-10-17 12:05:00+00:00'),
            text: 'A reply',
            lang: 'en',
            user: $user,
        );

        // Act & Assert (reply relationship)
        $this->assertTrue($replyTweet->isReplyTo('100'));

        // Act & Assert (roundtrip serialization)
        $array = $tweet->toArray();
        $restored = Tweet::fromArray($array);
        $this->assertSame($tweet->id, $restored->id);
        $this->assertSame($tweet->text, $restored->text);
        $this->assertSame(42, $restored->likes);
        $this->assertCount(1, $restored->media);
        $this->assertSame('https://img.jpg', $restored->media[0]->url);
    }

    /**
     * Verifies FetchStats serialization and value getters.
     */
    public function testFetchStatsModel(): void
    {
        // Arrange
        $stats = new FetchStats(
            requests: 2,
            pages: 2,
            newReplies: 15,
            fromCache: false,
            coalesced: false,
            fetchedAt: 1728000000,
        );

        // Act
        $array = $stats->toArray();

        // Assert
        $this->assertSame(2, $stats->requests);
        $this->assertSame(15, $stats->newReplies);
        $this->assertFalse($array['fromCache']);
    }

    /**
     * Verifies Conversation reply partitioning, iteration, merging, and serialization.
     */
    public function testConversationModel(): void
    {
        // Arrange
        $user = new User('10', 'mrpc', 'Pramnos');
        $focal = new Tweet('100', '100', null, new DateTimeImmutable(), 'Root post', 'en', $user);
        $r1 = new Tweet('101', '100', '100', new DateTimeImmutable(), 'Direct reply 1', 'en', $user);
        $r2 = new Tweet('102', '100', '101', new DateTimeImmutable(), 'Nested reply 2', 'en', $user);

        // Act
        $conv = new Conversation($focal, [$r1, $r2]);

        // Assert (counts and structure)
        $this->assertSame(2, $conv->replyCount());
        $this->assertCount(2, $conv);
        $this->assertCount(1, $conv->directReplies());
        $this->assertSame('101', $conv->directReplies()[0]->id);
        $this->assertCount(1, $conv->nestedReplies());
        $this->assertSame('102', $conv->nestedReplies()[0]->id);

        // Assert (lookups)
        $this->assertSame($focal, $conv->find('100'));
        $this->assertSame($r1, $conv->find('101'));
        $this->assertNull($conv->find('999'));
        $this->assertTrue($conv->hasReply('102'));
        $this->assertFalse($conv->hasReply('999'));

        // Act & Assert (Iterator)
        $iterated = iterator_to_array($conv);
        $this->assertCount(2, $iterated);

        // Act & Assert (withStats)
        $stats = new FetchStats(1, 1, 2, false, false, time());
        $withStats = $conv->withStats($stats);
        $this->assertSame($stats, $withStats->stats);

        // Act & Assert (withMergedReplies)
        $r3 = new Tweet('103', '100', '100', new DateTimeImmutable(), 'Direct reply 3', 'en', $user);
        $merged = $conv->withMergedReplies([$r1, $r3]);
        $this->assertSame(3, $merged->replyCount());
        $this->assertTrue($merged->hasReply('103'));

        // Act & Assert (toArray and fromArray)
        $convWithStats = $conv->withStats($stats);
        $arr = $convWithStats->toArray();
        $restoredConv = Conversation::fromArray($arr);
        $this->assertSame('100', $restoredConv->tweet->id);
        $this->assertSame(2, $restoredConv->replyCount());
        $this->assertNotNull($restoredConv->stats);
        $this->assertSame(1, $restoredConv->stats->requests);
    }

    /**
     * Verifies TimelineResult value object properties.
     */
    public function testTimelineResult(): void
    {
        // Arrange
        $user = new User('1', 'alice', 'Alice');
        $tweet = new Tweet('1', '1', null, null, 'Hi', null, $user);

        // Act
        $res = new TimelineResult($tweet, [$tweet], 'cursor-bot', 'cursor-top');

        // Assert
        $this->assertSame($tweet, $res->focalTweet);
        $this->assertCount(1, $res->replies);
        $this->assertSame('cursor-bot', $res->bottomCursor);
        $this->assertSame('cursor-top', $res->topCursor);
    }
}

