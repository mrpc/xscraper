<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Cache\FileCache;
use Mrpc\XScraper\Exception\QueryIdException;
use Mrpc\XScraper\Http\BrowserProfile;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\HttpResponse;
use Mrpc\XScraper\Http\Transport;
use Mrpc\XScraper\QueryIdResolver;
use Mrpc\XScraper\Session;
use PHPUnit\Framework\TestCase;

final class QueryIdResolverTest extends TestCase
{
    /**
     * Verifies default query IDs, programmatic overrides, default features, and field toggles.
     */
    public function testDefaultsAndOverrides(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();

        // Act & Assert (defaults)
        $this->assertSame(QueryIdResolver::DEFAULT_TWEET_DETAIL_QUERY_ID, $resolver->resolve('TweetDetail'));

        // Act & Assert (override)
        $resolver->set('TweetDetail', 'custom_query_id');
        $this->assertSame('custom_query_id', $resolver->resolve('TweetDetail'));

        // Act & Assert (features and toggles)
        $features = $resolver->defaultFeatures('TweetDetail');
        $this->assertArrayHasKey('responsive_web_graphql_exclude_directive_enabled', $features);

        $toggles = $resolver->defaultFieldToggles('TweetDetail');
        $this->assertArrayHasKey('withArticleRichContentState', $toggles);
    }

    /**
     * Verifies that setting an empty query ID throws InvalidArgumentException.
     */
    public function testSetEmptyThrows(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $resolver->set('TweetDetail', '');
    }

    /**
     * Verifies that resolving an unknown operation without default throws QueryIdException.
     */
    public function testMissingOperationThrows(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();

        // Assert
        $this->expectException(QueryIdException::class);

        // Act
        $resolver->resolve('UnknownOperation');
    }

    /**
     * Verifies reading query ID overrides from environment variables.
     */
    public function testEnvironmentOverrides(): void
    {
        // Arrange
        putenv('X_QUERY_ID_TWEET_DETAIL=env_tweet_detail_id');
        putenv('X_QUERY_IDS={"UserByScreenName":"user_by_screen_name_id"}');

        // Act
        $resolver = new QueryIdResolver();

        // Assert
        $this->assertSame('env_tweet_detail_id', $resolver->resolve('TweetDetail'));
        $this->assertSame('user_by_screen_name_id', $resolver->resolve('UserByScreenName'));

        // Cleanup
        putenv('X_QUERY_ID_TWEET_DETAIL');
        putenv('X_QUERY_IDS');
    }

    /**
     * Verifies extracting query ID token from raw JavaScript bundle content via regex.
     */
    public function testExtractQueryIdFromScript(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();
        $js1 = 'e.exports={queryId:"abcdef123456",operationName:"TweetDetail",operationType:"query"}';
        $js2 = 'module.exports={operationName:"TweetDetail",queryId:"xyz987654321"}';

        // Act & Assert
        $this->assertSame('abcdef123456', $resolver->extractQueryIdFromScript($js1, 'TweetDetail'));
        $this->assertSame('xyz987654321', $resolver->extractQueryIdFromScript($js2, 'TweetDetail'));
        $this->assertNull($resolver->extractQueryIdFromScript('random code without queryId', 'TweetDetail'));
    }

    /**
     * Verifies active discovery of query ID by scraping main page and referenced JS bundle.
     */
    public function testDiscoverSuccess(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();
        $transport = $this->createMock(Transport::class);
        $profile = BrowserProfile::chromeLinux();
        $session = new Session('auth', 'ct0');

        $html = '<html><head><script src="https://abs.twimg.com/responsive-web/client-web/main.12345.js"></script></head></html>';
        $bundleJs = 'someCode();{queryId:"DISCOVERED_ID_123",operationName:"TweetDetail",operationType:"query"};moreCode();';

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (HttpRequest $req) use ($html, $bundleJs) {
                if (str_contains($req->url, 'https://x.com/')) {
                    return new HttpResponse(200, [], $html);
                }
                if (str_contains($req->url, 'main.12345.js')) {
                    return new HttpResponse(200, [], $bundleJs);
                }
                return new HttpResponse(404, [], '');
            });

        // Act
        $discovered = $resolver->discover('TweetDetail', $transport, $profile, $session);

        // Assert
        $this->assertSame('DISCOVERED_ID_123', $discovered);
        $this->assertSame('DISCOVERED_ID_123', $resolver->resolve('TweetDetail'));
    }

    /**
     * Verifies that network failure during bundle discovery throws QueryIdException.
     */
    public function testDiscoverFailureThrows(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();
        $transport = $this->createMock(Transport::class);
        $profile = BrowserProfile::chromeLinux();
        $session = new Session('auth', 'ct0');
        $transport->method('send')->willReturn(new HttpResponse(500, [], 'Error'));

        // Assert
        $this->expectException(QueryIdException::class);

        // Act
        $resolver->discover('TweetDetail', $transport, $profile, $session);
    }

    /**
     * Verifies that discover uses cached query ID if available, skipping network calls.
     */
    public function testDiscoverUsingCache(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->never())->method('send');

        $profile = BrowserProfile::chromeLinux();
        $session = new Session('auth', 'ct0');

        $cache = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $cache->method('get')
            ->with('xscraper_queryid_TweetDetail')
            ->willReturn('CACHED_QUERY_ID');

        // Act
        $res = $resolver->discover('TweetDetail', $transport, $profile, $session, $cache);

        // Assert
        $this->assertSame('CACHED_QUERY_ID', $res);
    }

    /**
     * Verifies discovering query ID when the script tag uses a relative URL.
     */
    public function testDiscoverRelativeScriptUrl(): void
    {
        // Arrange
        $resolver = new QueryIdResolver();
        $transport = $this->createMock(Transport::class);
        $profile = BrowserProfile::chromeLinux();
        $session = new Session('auth', 'ct0');

        $html = '<html><head><script src="/responsive-web/client-web/bundle.rel.js"></script></head></html>';
        $bundleJs = 'e = {id: "REL_DISCOVERED_1", name: "TweetDetail"};';

        $transport->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (HttpRequest $req) use ($html, $bundleJs) {
                if ($req->url === 'https://x.com/') {
                    return new HttpResponse(200, [], $html);
                }
                return new HttpResponse(200, [], $bundleJs);
            });

        // Act
        $res = $resolver->discover('TweetDetail', $transport, $profile, $session);

        // Assert
        $this->assertSame('REL_DISCOVERED_1', $res);
    }
}

