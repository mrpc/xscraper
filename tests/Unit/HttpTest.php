<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use InvalidArgumentException;
use Mrpc\XScraper\Exception\TransportException;
use Mrpc\XScraper\Http\BrowserProfile;
use Mrpc\XScraper\Http\CurlTransport;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\HttpResponse;
use Mrpc\XScraper\Http\PramnosTransport;
use Mrpc\XScraper\Http\Psr18Transport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpTest extends TestCase
{
    public function testBrowserProfileChromeLinuxAndWindows(): void
    {
        $linux = BrowserProfile::chromeLinux();
        $this->assertStringContainsString('Linux', $linux->userAgent);
        $this->assertArrayHasKey('sec-ch-ua-platform', $linux->clientHints);

        $win = BrowserProfile::chromeWindows();
        $this->assertStringContainsString('Windows', $win->userAgent);

        // apiHeaders order and content
        $apiHeaders = $linux->apiHeaders('bearer123', 'ct0val', 'authval', 'https://x.com/ref');
        $headerNames = array_column($apiHeaders, 0);

        $this->assertContains('x-csrf-token', $headerNames);
        $this->assertContains('authorization', $headerNames);
        $this->assertContains('cookie', $headerNames);

        // Verify cookie header value
        $cookieHeader = null;
        foreach ($apiHeaders as [$k, $v]) {
            if ($k === 'cookie') {
                $cookieHeader = $v;
                break;
            }
        }
        $this->assertSame('auth_token=authval; ct0=ct0val', $cookieHeader);

        // documentHeaders
        $docHeaders = $linux->documentHeaders('ct0val', 'authval', script: false);
        $this->assertNotEmpty($docHeaders);
        $scriptHeaders = $linux->documentHeaders('ct0val', 'authval', script: true);
        $this->assertNotEmpty($scriptHeaders);
    }

    public function testHttpRequestAndResponse(): void
    {
        $req = new HttpRequest('GET', 'https://api.x.com/data', [['x-header', 'foo'], ['accept', '*/*']]);
        $this->assertSame('GET', $req->method);
        $map = $req->headerMap();
        $this->assertSame('foo', $map['x-header']);
        $this->assertSame('*/*', $map['accept']);

        $res = new HttpResponse(200, ['content-type' => 'application/json', 'x-custom' => 'bar'], '{"status":"ok"}');
        $this->assertSame(200, $res->status);
        $this->assertSame('bar', $res->header('X-Custom'));
        $this->assertSame('', $res->header('missing'));
        $this->assertSame(['status' => 'ok'], $res->json());

        $badRes = new HttpResponse(500, [], 'not json');
        $this->assertNull($badRes->json());
    }

    public function testCurlTransportThrowsOnInvalidUrl(): void
    {
        $transport = new CurlTransport(connectTimeout: 2, proxy: 'http://127.0.0.1:9999');
        $req = new HttpRequest('GET', 'http://invalid.domain.that.does.not.exist.example:81', timeout: 1);

        $this->expectException(TransportException::class);
        $transport->send($req);
    }

    public function testPramnosTransportProxyValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PramnosTransport::withProxy('invalid proxy string');
    }

    public function testPsr18TransportSuccess(): void
    {
        $psrClient = $this->createMock(ClientInterface::class);
        $reqFactory = $this->createMock(RequestFactoryInterface::class);
        $psrReq = $this->createMock(RequestInterface::class);
        $psrRes = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);

        $reqFactory->expects($this->once())
            ->method('createRequest')
            ->with('GET', 'https://x.com/test')
            ->willReturn($psrReq);

        $psrReq->expects($this->any())
            ->method('withHeader')
            ->willReturnSelf();

        $stream->expects($this->any())
            ->method('read')
            ->willReturn('{"ok":true}');
        $stream->expects($this->any())
            ->method('eof')
            ->willReturn(true);

        $psrRes->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $psrRes->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['Content-Type' => ['application/json']]);
        $psrRes->expects($this->once())
            ->method('getBody')
            ->willReturn($stream);

        $psrClient->expects($this->once())
            ->method('sendRequest')
            ->with($psrReq)
            ->willReturn($psrRes);

        $transport = new Psr18Transport($psrClient, $reqFactory);
        $response = $transport->send(new HttpRequest('GET', 'https://x.com/test', [['accept', 'json']]));

        $this->assertSame(200, $response->status);
        $this->assertSame(['ok' => true], $response->json());
    }

    public function testPsr18TransportError(): void
    {
        $psrClient = $this->createMock(ClientInterface::class);
        $reqFactory = $this->createMock(RequestFactoryInterface::class);
        $psrReq = $this->createMock(RequestInterface::class);

        $reqFactory->method('createRequest')->willReturn($psrReq);

        $exception = new class ('Network error') extends \Exception implements ClientExceptionInterface {
        };

        $psrClient->method('sendRequest')->willThrowException($exception);

        $transport = new Psr18Transport($psrClient, $reqFactory);

        $this->expectException(TransportException::class);
        $transport->send(new HttpRequest('GET', 'https://x.com/test'));
    }

    public function testCurlTransportRealRequests(): void
    {
        $port = 18991;
        $docRoot = realpath(__DIR__ . '/../../');
        $pid = exec("php -S 127.0.0.1:{$port} -t " . escapeshellarg($docRoot) . " > /dev/null 2>&1 & echo $!");
        usleep(250000);

        try {
            $transport = new CurlTransport(connectTimeout: 2);

            // 1. GET
            $reqGet = new HttpRequest('GET', "http://127.0.0.1:{$port}/composer.json", [['Accept', 'application/json']]);
            $resGet = $transport->send($reqGet);
            $this->assertSame(200, $resGet->status);
            $this->assertFalse($resGet->truncated);
            $this->assertNotEmpty($resGet->body);

            // 2. POST
            $reqPost = new HttpRequest('POST', "http://127.0.0.1:{$port}/composer.json");
            $resPost = $transport->send($reqPost);
            $this->assertSame(200, $resPost->status);

            // 3. Custom method
            $reqPut = new HttpRequest('PUT', "http://127.0.0.1:{$port}/composer.json");
            $resPut = $transport->send($reqPut);
            $this->assertSame(405, $resPut->status);

            // 4. Truncation
            $reqTrunc = new HttpRequest('GET', "http://127.0.0.1:{$port}/composer.json", maxBodyBytes: 50);
            $resTrunc = $transport->send($reqTrunc);
            $this->assertTrue($resTrunc->truncated);
            $this->assertSame(50, strlen($resTrunc->body));
        } finally {
            exec("kill {$pid} 2>/dev/null");
        }
    }
}
