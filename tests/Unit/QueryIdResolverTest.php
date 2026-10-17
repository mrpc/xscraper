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
    public function testDefaultsAndOverrides(): void
    {
        $resolver = new QueryIdResolver();
        $this->assertSame(QueryIdResolver::DEFAULT_TWEET_DETAIL_QUERY_ID, $resolver->resolve('TweetDetail'));

        $resolver->set('TweetDetail', 'custom_query_id');
        $this->assertSame('custom_query_id', $resolver->resolve('TweetDetail'));

        $features = $resolver->defaultFeatures('TweetDetail');
        $this->assertArrayHasKey('responsive_web_graphql_exclude_directive_enabled', $features);

        $toggles = $resolver->defaultFieldToggles('TweetDetail');
        $this->assertArrayHasKey('withArticleRichContentState', $toggles);
    }

    public function testSetEmptyThrows(): void
    {
        $resolver = new QueryIdResolver();
        $this->expectException(InvalidArgumentException::class);
        $resolver->set('TweetDetail', '');
    }

    public function testMissingOperationThrows(): void
    {
        $resolver = new QueryIdResolver();
        $this->expectException(QueryIdException::class);
        $resolver->resolve('UnknownOperation');
    }

    public function testEnvironmentOverrides(): void
    {
        putenv('X_QUERY_ID_TWEET_DETAIL=env_tweet_detail_id');
        putenv('X_QUERY_IDS={"UserByScreenName":"user_by_screen_name_id"}');

        $resolver = new QueryIdResolver();
        $this->assertSame('env_tweet_detail_id', $resolver->resolve('TweetDetail'));
        $this->assertSame('user_by_screen_name_id', $resolver->resolve('UserByScreenName'));

        putenv('X_QUERY_ID_TWEET_DETAIL');
        putenv('X_QUERY_IDS');
    }

    public function testExtractQueryIdFromScript(): void
    {
        $resolver = new QueryIdResolver();

        $js1 = 'e.exports={queryId:"abcdef123456",operationName:"TweetDetail",operationType:"query"}';
        $this->assertSame('abcdef123456', $resolver->extractQueryIdFromScript($js1, 'TweetDetail'));

        $js2 = 'module.exports={operationName:"TweetDetail",queryId:"xyz987654321"}';
        $this->assertSame('xyz987654321', $resolver->extractQueryIdFromScript($js2, 'TweetDetail'));

        $this->assertNull($resolver->extractQueryIdFromScript('random code without queryId', 'TweetDetail'));
    }

    public function testDiscoverSuccess(): void
    {
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

        $discovered = $resolver->discover('TweetDetail', $transport, $profile, $session);
        $this->assertSame('DISCOVERED_ID_123', $discovered);
        $this->assertSame('DISCOVERED_ID_123', $resolver->resolve('TweetDetail'));
    }

    public function testDiscoverFailureThrows(): void
    {
        $resolver = new QueryIdResolver();
        $transport = $this->createMock(Transport::class);
        $profile = BrowserProfile::chromeLinux();
        $session = new Session('auth', 'ct0');

        $transport->method('send')->willReturn(new HttpResponse(500, [], 'Error'));

        $this->expectException(QueryIdException::class);
        $resolver->discover('TweetDetail', $transport, $profile, $session);
    }

    public function testDiscoverUsingCache(): void
    {
        $resolver = new QueryIdResolver();
        $transport = $this->createMock(Transport::class);
        $transport->expects($this->never())->method('send');

        $profile = BrowserProfile::chromeLinux();
        $session = new Session('auth', 'ct0');

        $cache = $this->createMock(\Psr\SimpleCache\CacheInterface::class);
        $cache->method('get')
            ->with('xscraper_queryid_TweetDetail')
            ->willReturn('CACHED_QUERY_ID');

        $res = $resolver->discover('TweetDetail', $transport, $profile, $session, $cache);
        $this->assertSame('CACHED_QUERY_ID', $res);
    }

    public function testDiscoverRelativeScriptUrl(): void
    {
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

        $res = $resolver->discover('TweetDetail', $transport, $profile, $session);
        $this->assertSame('REL_DISCOVERED_1', $res);
    }
}

