<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

require_once __DIR__ . '/../Support/PramnosStub.php';

use Mrpc\XScraper\Bridge\ChromeBridge;
use Mrpc\XScraper\Exception\ResponseException;
use Mrpc\XScraper\Exception\TweetNotFoundException;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\HttpResponse;
use Mrpc\XScraper\Http\Transport;
use Mrpc\XScraper\Session;
use PHPUnit\Framework\TestCase;

final class ChromeBridgeTest extends TestCase
{
    /**
     * Verifies that isHealthy() returns true on HTTP 200 with status ok and false on errors.
     */
    public function testIsHealthyReturnsStatus(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], '{"status":"ok"}'),
                new HttpResponse(500, [], '{"status":"error"}'),
                new HttpResponse(200, [], 'not json'),
            );

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Act & Assert (healthy)
        $this->assertTrue($bridge->isHealthy());

        // Act & Assert (500 status)
        $this->assertFalse($bridge->isHealthy());

        // Act & Assert (malformed json)
        $this->assertFalse($bridge->isHealthy());
    }

    /**
     * Verifies that isHealthy() gracefully catches Transport exceptions and returns false.
     */
    public function testIsHealthyCatchesException(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willThrowException(new \RuntimeException('Connection refused'));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Act
        $healthy = $bridge->isHealthy();

        // Assert
        $this->assertFalse($healthy);
    }

    /**
     * Verifies successful scrape dispatch and conversation reconstruction from captured GraphQL responses.
     */
    public function testScrapeSuccessWithGraphql(): void
    {
        // Arrange
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        $graphqlPayload = [
            'data' => [
                'threaded_conversation_with_injections_v2' => [
                    'instructions' => [
                        [
                            'type' => 'TimelineAddEntries',
                            'entries' => [
                                [
                                    'entryId' => 'tweet-' . $focalId,
                                    'content' => [
                                        'entryType' => 'TimelineTimelineItem',
                                        'itemContent' => [
                                            'itemType' => 'TimelineTweet',
                                            'tweet_results' => [
                                                'result' => [
                                                    '__typename' => 'Tweet',
                                                    'rest_id' => $focalId,
                                                    'legacy' => [
                                                        'full_text' => 'Headless scraped tweet',
                                                        'created_at' => 'Sat Oct 17 12:00:00 +0000 2026',
                                                    ],
                                                    'core' => [
                                                        'user_results' => [
                                                            'result' => [
                                                                '__typename' => 'User',
                                                                'rest_id' => '1',
                                                                'legacy' => ['screen_name' => 'jack', 'name' => 'Jack'],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'entryId' => 'tweet-201',
                                    'content' => [
                                        'entryType' => 'TimelineTimelineItem',
                                        'itemContent' => [
                                            'itemType' => 'TimelineTweet',
                                            'tweet_results' => [
                                                'result' => [
                                                    '__typename' => 'Tweet',
                                                    'rest_id' => '201',
                                                    'legacy' => [
                                                        'full_text' => 'Headless reply text',
                                                        'in_reply_to_status_id_str' => $focalId,
                                                        'created_at' => 'Sat Oct 17 12:05:00 +0000 2026',
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

        $serviceResponse = [
            'success' => true,
            'tweetId' => $focalId,
            'url' => 'https://x.com/i/status/' . $focalId,
            'capturedGraphql' => [$graphqlPayload],
            'domTweets' => [],
            'stats' => ['durationMs' => 1500],
        ];

        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (HttpRequest $req) use ($focalId) {
                return $req->method === 'POST'
                    && str_contains($req->url, '/scrape')
                    && str_contains($req->body, $focalId);
            }))
            ->willReturn(new HttpResponse(200, [], (string) json_encode($serviceResponse)));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);
        $session = new Session('auth_token_val', 'ct0_val');

        // Act
        $conv = $bridge->scrape($focalId, $session, maxScrolls: 4, timeout: 20);

        // Assert
        $this->assertSame($focalId, $conv->tweet->id);
        $this->assertSame('Headless scraped tweet', $conv->tweet->text);
        $this->assertSame(1, $conv->replyCount());
        $this->assertTrue($conv->hasReply('201'));
        $this->assertSame('http://chrome-scraper:3000', $bridge->serviceUrl());
    }

    /**
     * Verifies DOM tweet extraction fallback when no GraphQL payload was captured.
     */
    public function testScrapeFallbackToDomTweets(): void
    {
        // Arrange
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);

        $serviceResponse = [
            'success' => true,
            'tweetId' => $focalId,
            'url' => 'https://x.com/i/status/' . $focalId,
            'capturedGraphql' => [],
            'domTweets' => [
                [
                    'text' => 'Fallback DOM tweet text',
                    'author' => 'DOM Author',
                    'url' => 'https://x.com/author/status/' . $focalId,
                ],
            ],
            'stats' => ['durationMs' => 1200],
        ];

        $transport->method('send')->willReturn(new HttpResponse(200, [], (string) json_encode($serviceResponse)));
        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Act
        $conv = $bridge->scrape($focalId);

        // Assert
        $this->assertSame($focalId, $conv->tweet->id);
        $this->assertSame('Fallback DOM tweet text', $conv->tweet->text);
        $this->assertSame('DOM Author', $conv->tweet->user->screenName);
    }

    /**
     * Verifies that HTTP 404 from the chrome service throws TweetNotFoundException.
     */
    public function testScrapeThrowsNotFoundOn404(): void
    {
        // Arrange
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(404, [], '{"error":"Post not found"}'));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Assert
        $this->expectException(TweetNotFoundException::class);

        // Act
        $bridge->scrape($focalId);
    }

    /**
     * Verifies that HTTP 500 error from the chrome service throws ResponseException.
     */
    public function testScrapeThrowsResponseExceptionOn500(): void
    {
        // Arrange
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(500, [], '{"error":"Chrome navigation timed out"}'));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Assert
        $this->expectException(ResponseException::class);

        // Act
        $bridge->scrape($focalId);
    }

    /**
     * Verifies that success=false in JSON body throws ResponseException.
     */
    public function testScrapeThrowsResponseExceptionOnSuccessFalse(): void
    {
        // Arrange
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(200, [], '{"success":false,"error":"Browser crashed"}'));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Assert
        $this->expectException(ResponseException::class);

        // Act
        $bridge->scrape($focalId);
    }

    /**
     * Verifies that empty results without tweet throw TweetNotFoundException.
     */
    public function testScrapeThrowsWhenNoTweetExtracted(): void
    {
        // Arrange
        $focalId = '1845187747970863384';
        $transport = $this->createMock(Transport::class);
        $transport->method('send')->willReturn(new HttpResponse(200, [], '{"success":true,"capturedGraphql":[],"domTweets":[]}'));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Assert
        $this->expectException(TweetNotFoundException::class);

        // Act
        $bridge->scrape($focalId);
    }

    /**
     * Verifies default transport selection when none is explicitly provided.
     */
    public function testDefaultTransportSelection(): void
    {
        // Act
        $bridge = new ChromeBridge();

        // Assert
        $this->assertSame('http://127.0.0.1:3000', $bridge->serviceUrl());
    }

    /**
     * Verifies successful automated login request dispatch to the Chrome microservice.
     */
    public function testLoginSuccess(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $expectedResponse = [
            'success' => true,
            'message' => 'Logged in successfully',
            'username' => 'testuser',
            'hasAuthToken' => true,
            'hasCt0' => true,
        ];

        $transport->expects($this->once())
            ->method('send')
            ->with($this->callback(function (HttpRequest $req) {
                return $req->method === 'POST'
                    && str_contains($req->url, '/login')
                    && str_contains($req->body, 'testuser');
            }))
            ->willReturn(new HttpResponse(200, [], (string) json_encode($expectedResponse)));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Act
        $result = $bridge->login('testuser', 'secret123', 'confirm@test.com', headed: true);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertSame('testuser', $result['username']);
    }

    /**
     * Verifies that login failure throws ResponseException.
     */
    public function testLoginThrowsResponseExceptionOnFailure(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $transport->method('send')
            ->willReturn(new HttpResponse(401, [], '{"success":false,"error":"Invalid credentials"}'));

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Assert
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Invalid credentials');

        // Act
        $bridge->login('testuser', 'wrongpass');
    }

    /**
     * Verifies getSession retrieves current session status and returns null on error.
     */
    public function testGetSessionStatus(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], '{"loggedIn":true,"username":"jack"}'),
                new HttpResponse(500, [], '{"error":"server error"}'),
                new HttpResponse(200, [], 'not json'),
            );

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Act & Assert (valid session)
        $session = $bridge->getSession();
        $this->assertIsArray($session);
        $this->assertTrue($session['loggedIn']);
        $this->assertSame('jack', $session['username']);

        // Act & Assert (HTTP 500 error)
        $this->assertNull($bridge->getSession());

        // Act & Assert (invalid JSON response)
        $this->assertNull($bridge->getSession());
    }

    /**
     * Verifies logout requests and response handling.
     */
    public function testLogout(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], '{"success":true}'),
                new HttpResponse(500, [], '{"success":false}'),
            );

        $bridge = new ChromeBridge('http://chrome-scraper:3000', $transport);

        // Act & Assert (successful logout)
        $this->assertTrue($bridge->logout());

        // Act & Assert (failed logout)
        $this->assertFalse($bridge->logout());
    }

    /**
     * Verifies that parseStreamLines correctly invokes onProgress and returns completed login data.
     */
    public function testParseStreamLinesSuccess(): void
    {
        // Arrange
        $bridge = new ChromeBridge();
        $lines = [
            json_encode(['type' => 'progress', 'stage' => 'navigating']),
            '',
            json_encode(['type' => 'progress', 'stage' => 'entering_username']),
            json_encode(['type' => 'complete', 'success' => true, 'username' => 'testuser', 'authToken' => 'tok']),
        ];

        $events = [];
        $callback = function (array $evt) use (&$events): void {
            $events[] = $evt;
        };

        // Act
        $result = $bridge->parseStreamLines($lines, $callback);

        // Assert
        $this->assertSame(3, count($events));
        $this->assertTrue($result['success']);
        $this->assertSame('testuser', $result['username']);
    }

    /**
     * Verifies that parseStreamLines throws ResponseException on streaming error or missing completion.
     */
    public function testParseStreamLinesThrowsOnError(): void
    {
        // Arrange
        $bridge = new ChromeBridge();
        $errorLines = [
            json_encode(['type' => 'error', 'success' => false, 'error' => 'Login timed out']),
        ];

        // Assert
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Login timed out');

        // Act
        $bridge->parseStreamLines($errorLines);
    }

    /**
     * Verifies that parseStreamLines throws ResponseException when stream is empty without result.
     */
    public function testParseStreamLinesThrowsOnEmptyStream(): void
    {
        // Arrange
        $bridge = new ChromeBridge();

        // Assert
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Connection closed without completing login');

        // Act
        $bridge->parseStreamLines(['', 'not-json-line']);
    }

    /**
     * Verifies that login() with onProgress delegates to streaming and falls back on fopen failure.
     */
    public function testLoginDelegatesToStreamWhenProgressCallbackProvided(): void
    {
        // Arrange
        $transport = $this->createMock(Transport::class);
        $transport->method('send')
            ->willReturn(new HttpResponse(200, [], '{"success":true,"username":"streamuser"}'));

        // Use an invalid local port so fopen fails and triggers the fallback to login()
        $bridge = new ChromeBridge('http://127.0.0.1:59998', $transport);
        $called = false;

        // Act
        $result = $bridge->login(
            username: 'streamuser',
            headed: true,
            onProgress: function () use (&$called): void {
                $called = true;
            },
        );

        // Assert
        $this->assertTrue($result['success']);
        $this->assertSame('streamuser', $result['username']);
    }
}


