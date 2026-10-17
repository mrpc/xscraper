<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Integration;

use Mrpc\XScraper\Cache\FileCache;
use Mrpc\XScraper\Cache\TtlPolicy;
use Mrpc\XScraper\Exception\AuthenticationException;
use Mrpc\XScraper\Exception\RateLimitException;
use Mrpc\XScraper\Exception\ResponseException;
use Mrpc\XScraper\Exception\TweetNotFoundException;
use Mrpc\XScraper\FetchMode;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\HttpResponse;
use Mrpc\XScraper\Http\Transport;
use Mrpc\XScraper\Lock\FileLockFactory;
use Mrpc\XScraper\RateLimit\Throttle;
use Mrpc\XScraper\Session;
use Mrpc\XScraper\XScraper;
use PHPUnit\Framework\TestCase;

final class XScraperTest extends TestCase
{
    private string $tempDir;
    private Session $session;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/xscraper_test_' . bin2hex(random_bytes(4));
        $this->session = new Session('valid_auth_token', 'valid_ct0_token', 'test_user');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function createFakeResponse(string $focalId, array $replyIds, ?string $nextCursor = null): array
    {
        $entries = [
            [
                'entryId' => "tweet-{$focalId}",
                'content' => [
                    'entryType' => 'TimelineTimelineItem',
                    'itemContent' => [
                        'itemType' => 'TimelineTweet',
                        'tweet_results' => [
                            'result' => [
                                '__typename' => 'Tweet',
                                'rest_id' => $focalId,
                                'core' => [
                                    'user_results' => [
                                        'result' => [
                                            'rest_id' => 'u1',
                                            'legacy' => ['screen_name' => 'focal_user', 'name' => 'Focal'],
                                        ],
                                    ],
                                ],
                                'legacy' => [
                                    'conversation_id_str' => $focalId,
                                    'created_at' => 'Sat Oct 17 10:00:00 +0000 2026',
                                    'full_text' => 'Focal post text',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($replyIds as $rId) {
            $entries[] = [
                'entryId' => "conversationthread-{$rId}",
                'content' => [
                    'entryType' => 'TimelineTimelineItem',
                    'itemContent' => [
                        'itemType' => 'TimelineTweet',
                        'tweet_results' => [
                            'result' => [
                                '__typename' => 'Tweet',
                                'rest_id' => (string) $rId,
                                'core' => [
                                    'user_results' => [
                                        'result' => [
                                            'rest_id' => 'u_' . $rId,
                                            'legacy' => ['screen_name' => 'user_' . $rId, 'name' => 'User ' . $rId],
                                        ],
                                    ],
                                ],
                                'legacy' => [
                                    'conversation_id_str' => $focalId,
                                    'in_reply_to_status_id_str' => $focalId,
                                    'created_at' => 'Sat Oct 17 10:05:00 +0000 2026',
                                    'full_text' => "Reply {$rId} text",
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($nextCursor !== null) {
            $entries[] = [
                'entryId' => 'cursor-bottom-999',
                'content' => [
                    'entryType' => 'TimelineTimelineCursor',
                    'cursorType' => 'Bottom',
                    'value' => $nextCursor,
                ],
            ];
        }

        return [
            'data' => [
                'threaded_conversation_with_injections_v2' => [
                    'instructions' => [
                        [
                            'type' => 'TimelineAddEntries',
                            'entries' => $entries,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testSmartModeColdThenWarm(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        $fakeJson = $this->createFakeResponse($focalId, ['201', '202']);
        $transport->expects($this->once()) // Exactly 1 request on cold; 0 on warm
            ->method('send')
            ->willReturn(new HttpResponse(200, [], (string) json_encode($fakeJson)));

        $cache = new FileCache($this->tempDir . '/cache');
        $lockFactory = new FileLockFactory($this->tempDir . '/locks');
        $throttle = new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0);
        $ttlPolicy = TtlPolicy::fixed(3600); // fresh for 1 hour

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: $cache,
            lockFactory: $lockFactory,
            throttle: $throttle,
            ttlPolicy: $ttlPolicy,
        );

        // 1. Cold fetch
        $conv = $scraper->getConversation($focalId, FetchMode::Smart);
        $this->assertSame($focalId, $conv->tweet->id);
        $this->assertSame(2, $conv->replyCount());
        $this->assertFalse($conv->stats->fromCache);
        $this->assertSame(1, $conv->stats->requests);

        // 2. Warm fetch: served from cache immediately without network calls
        $warmConv = $scraper->getConversation($focalId, FetchMode::Smart);
        $this->assertSame($focalId, $warmConv->tweet->id);
        $this->assertSame(2, $warmConv->replyCount());
        $this->assertTrue($warmConv->stats->fromCache);
        $this->assertSame(0, $warmConv->stats->requests);
    }

    public function testIncrementalPaginationStopsAtKnownReply(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        // Seed cache with older reply 201
        $initialJson = $this->createFakeResponse($focalId, ['201']);
        $cache = new FileCache($this->tempDir . '/cache');
        $lockFactory = new FileLockFactory($this->tempDir . '/locks');
        $throttle = new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0);
        $ttlPolicy = TtlPolicy::fixed(3600);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], (string) json_encode($initialJson)),
                // On refresh: returns page with newest reply 202 AND already known reply 201 + a bottom cursor
                new HttpResponse(200, [], (string) json_encode($this->createFakeResponse($focalId, ['202', '201'], 'cursor_to_older_pages'))),
            );

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: $cache,
            lockFactory: $lockFactory,
            throttle: $throttle,
            ttlPolicy: $ttlPolicy,
        );

        // First fetch
        $scraper->getConversation($focalId, FetchMode::Smart);

        // Second fetch: should see known reply 201 on page 1 and NOT request cursor_to_older_pages!
        $refreshed = $scraper->getConversation($focalId, FetchMode::Refresh);
        $this->assertSame(2, $refreshed->replyCount()); // 201 and 202 merged
        $this->assertTrue($refreshed->hasReply('201'));
        $this->assertTrue($refreshed->hasReply('202'));
        $this->assertSame(1, $refreshed->stats->pages); // Stopped after page 1!
    }

    public function testCacheOnlyMode(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->never())->method('send');

        $cache = new FileCache($this->tempDir . '/cache');
        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: $cache,
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        // Missing throws TweetNotFoundException
        $this->expectException(TweetNotFoundException::class);
        $scraper->getConversation($focalId, FetchMode::CacheOnly);
    }

    public function testCookieRotationDetection(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        $fakeJson = $this->createFakeResponse($focalId, []);
        $headers = [
            'set-cookie' => 'ct0=rotated_ct0_value_999; Path=/; Domain=.x.com; Secure',
        ];

        $transport->method('send')
            ->willReturn(new HttpResponse(200, $headers, (string) json_encode($fakeJson)));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $this->assertSame('valid_ct0_token', $scraper->session()->ct0);
        $scraper->getConversation($focalId);
        $this->assertSame('rotated_ct0_value_999', $scraper->session()->ct0);
    }

    public function testAuthenticationFailureThrows(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(401, [], 'Unauthorized'));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $this->expectException(AuthenticationException::class);
        $scraper->getConversation($focalId);
    }

    public function testNotFoundThrows(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(404, [], 'Not Found'));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $this->expectException(TweetNotFoundException::class);
        $scraper->getConversation($focalId);
    }

    public function testRateLimit429Throws(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(429, ['x-rate-limit-reset' => (string) (time() + 900)], 'Too Many Requests'));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $this->expectException(RateLimitException::class);
        $scraper->getConversation($focalId);
    }

    public function testInvalidJsonThrowsResponseException(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(200, [], 'not json'));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $this->expectException(ResponseException::class);
        $scraper->getConversation($focalId);
    }

    public function testGetTweetHelper(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $fakeJson = $this->createFakeResponse($focalId, ['201']);
        $transport->method('send')->willReturn(new HttpResponse(200, [], (string) json_encode($fakeJson)));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $tweet = $scraper->getTweet($focalId);
        $this->assertSame($focalId, $tweet->id);
    }

    public function testCreateFactory(): void
    {
        $scraper = XScraper::create($this->session, storageDir: $this->tempDir);
        $this->assertInstanceOf(XScraper::class, $scraper);
    }

    public function testFullModeDiscardsCache(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        $initialJson = $this->createFakeResponse($focalId, ['201', '202']);
        $fullJson = $this->createFakeResponse($focalId, ['301']); // brand new replies

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], (string) json_encode($initialJson)),
                new HttpResponse(200, [], (string) json_encode($fullJson)),
            );

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $first = $scraper->getConversation($focalId, FetchMode::Smart);
        $this->assertSame(2, $first->replyCount());

        $full = $scraper->getConversation($focalId, FetchMode::Full);
        $this->assertSame(1, $full->replyCount());
        $this->assertTrue($full->hasReply('301'));
        $this->assertFalse($full->hasReply('201')); // Old cached replies discarded
    }

    public function testMultiPagePagination(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        $page1Json = $this->createFakeResponse($focalId, ['201'], 'cursor_p2');
        $page2Json = $this->createFakeResponse($focalId, ['202'], null);

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], (string) json_encode($page1Json)),
                new HttpResponse(200, [], (string) json_encode($page2Json)),
            );

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $conv = $scraper->getConversation($focalId, FetchMode::Full, maxReplies: 10);
        $this->assertSame(2, $conv->replyCount());
        $this->assertSame(2, $conv->stats->pages);
        $this->assertTrue($conv->hasReply('201'));
        $this->assertTrue($conv->hasReply('202'));
    }

    public function testCorruptedCacheEntryTreatedAsMiss(): void
    {
        $focalId = '1845187747970863384';
        $cache = new FileCache($this->tempDir . '/cache');
        $cache->set('x_conv_' . $focalId, ['tweet' => 'corrupted_structure_not_array']);

        $transport = $this->createMock(Transport::class);
        $fakeJson = $this->createFakeResponse($focalId, ['201']);
        $transport->expects($this->once())
            ->method('send')
            ->willReturn(new HttpResponse(200, [], (string) json_encode($fakeJson)));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: $cache,
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $conv = $scraper->getConversation($focalId, FetchMode::Smart);
        $this->assertSame(1, $conv->replyCount());
    }

    public function testCoalescedResultInsideLock(): void
    {
        $focalId = '1845187747970863384';
        $cache = new FileCache($this->tempDir . '/cache');
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->never())->method('send');

        // Fake lock factory that seeds cache before running callback
        $lockFactory = new class ($cache, $focalId) implements \Mrpc\XScraper\Lock\LockFactory {
            public function __construct(private FileCache $cache, private string $focalId)
            {
            }

            public function synchronized(string $name, float $timeoutSeconds, callable $callback): mixed
            {
                // Another concurrent process just saved this into cache!
                $user = new \Mrpc\XScraper\Model\User('1', 'alice', 'Alice');
                $tweet = new \Mrpc\XScraper\Model\Tweet($this->focalId, $this->focalId, null, new \DateTimeImmutable(), 'Coalesced', 'en', $user);
                $conv = new \Mrpc\XScraper\Model\Conversation($tweet, [$tweet], new \Mrpc\XScraper\Model\FetchStats(1, 1, 1, false, false, time()));
                $this->cache->set('x_conv_' . $this->focalId, $conv->toArray(), 3600);

                return $callback();
            }
        };

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: $cache,
            lockFactory: $lockFactory,
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $conv = $scraper->getConversation($focalId, FetchMode::Smart);
        $this->assertTrue($conv->stats->fromCache);
        $this->assertTrue($conv->stats->coalesced);
        $this->assertSame(0, $conv->stats->requests);
    }

    public function testMissingFocalTweetFallsBackToCachedOrThrows(): void
    {
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        // Response without focal tweet
        $jsonWithoutFocal = [
            'data' => [
                'threaded_conversation_with_injections_v2' => [
                    'instructions' => [],
                ],
            ],
        ];
        $transport->method('send')->willReturn(new HttpResponse(200, [], (string) json_encode($jsonWithoutFocal)));

        $scraper = new XScraper(
            $this->session,
            transport: $transport,
            cache: new FileCache($this->tempDir . '/cache'),
            lockFactory: new FileLockFactory($this->tempDir . '/locks'),
            throttle: new Throttle($this->tempDir . '/throttle', minDelay: 0.0, maxDelay: 0.0),
        );

        $this->expectException(TweetNotFoundException::class);
        $scraper->getConversation($focalId, FetchMode::Full);
    }

    public function testSessionGetter(): void
    {
        $scraper = new XScraper($this->session);
        $this->assertSame($this->session, $scraper->session());
    }
}
