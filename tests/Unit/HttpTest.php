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
    /**
     * Verifies BrowserProfile header generation and ordering for Linux and Windows profiles.
     */
    public function testBrowserProfileChromeLinuxAndWindows(): void
    {
        // Arrange
        $linux = BrowserProfile::chromeLinux();
        $win = BrowserProfile::chromeWindows();

        // Act & Assert (User agent and client hints)
        $this->assertStringContainsString('Linux', $linux->userAgent);
        $this->assertArrayHasKey('sec-ch-ua-platform', $linux->clientHints);
        $this->assertStringContainsString('Windows', $win->userAgent);

        // Act (apiHeaders generation)
        $apiHeaders = $linux->apiHeaders('bearer123', 'ct0val', 'authval', 'https://x.com/ref');
        $headerNames = array_column($apiHeaders, 0);

        // Assert (apiHeaders content)
        $this->assertContains('x-csrf-token', $headerNames);
        $this->assertContains('authorization', $headerNames);
        $this->assertContains('cookie', $headerNames);

        $cookieHeader = null;
        foreach ($apiHeaders as [$k, $v]) {
            if ($k === 'cookie') {
                $cookieHeader = $v;
                break;
            }
        }
        $this->assertSame('auth_token=authval; ct0=ct0val', $cookieHeader);

        // Act & Assert (documentHeaders)
        $docHeaders = $linux->documentHeaders('ct0val', 'authval', script: false);
        $this->assertNotEmpty($docHeaders);
        $scriptHeaders = $linux->documentHeaders('ct0val', 'authval', script: true);
        $this->assertNotEmpty($scriptHeaders);
    }

    /**
     * Verifies HttpRequest header map creation and HttpResponse status/JSON decoding.
     */
    public function testHttpRequestAndResponse(): void
    {
        // Arrange
        $req = new HttpRequest('GET', 'https://api.x.com/data', [['x-header', 'foo'], ['accept', '*/*']]);

        // Act & Assert (request headers map)
        $this->assertSame('GET', $req->method);
        $map = $req->headerMap();
        $this->assertSame('foo', $map['x-header']);
        $this->assertSame('*/*', $map['accept']);

        // Arrange (response)
        $res = new HttpResponse(200, ['content-type' => 'application/json', 'x-custom' => 'bar'], '{"status":"ok"}');
        $badRes = new HttpResponse(500, [], 'not json');

        // Act & Assert (response parsing)
        $this->assertSame(200, $res->status);
        $this->assertSame('bar', $res->header('X-Custom'));
        $this->assertSame('', $res->header('missing'));
        $this->assertSame(['status' => 'ok'], $res->json());
        $this->assertNull($badRes->json());
    }

    /**
     * Verifies that CurlTransport throws TransportException on connection failure.
     */
    public function testCurlTransportThrowsOnInvalidUrl(): void
    {
        // Arrange
        $transport = new CurlTransport(connectTimeout: 2, proxy: 'http://127.0.0.1:9999');
        $req = new HttpRequest('GET', 'http://invalid.domain.that.does.not.exist.example:81', timeout: 1);

        // Assert
        $this->expectException(TransportException::class);

        // Act
        $transport->send($req);
    }

    /**
     * Verifies that invalid proxy URL format throws InvalidArgumentException.
     */
    public function testPramnosTransportProxyValidation(): void
    {
        // Arrange & Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        PramnosTransport::withProxy('invalid proxy string');
    }

    /**
     * Verifies successful request execution and response mapping using Psr18Transport adapter.
     */
    public function testPsr18TransportSuccess(): void
    {
        // Arrange
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

        // Act
        $response = $transport->send(new HttpRequest('GET', 'https://x.com/test', [['accept', 'json']]));

        // Assert
        $this->assertSame(200, $response->status);
        $this->assertSame(['ok' => true], $response->json());
    }

    /**
     * Verifies that ClientExceptionInterface during PSR-18 call is converted to TransportException.
     */
    public function testPsr18TransportError(): void
    {
        // Arrange
        $psrClient = $this->createMock(ClientInterface::class);
        $reqFactory = $this->createMock(RequestFactoryInterface::class);
        $psrReq = $this->createMock(RequestInterface::class);
        $reqFactory->method('createRequest')->willReturn($psrReq);

        $exception = new class ('Network error') extends \Exception implements ClientExceptionInterface {
        };
        $psrClient->method('sendRequest')->willThrowException($exception);

        $transport = new Psr18Transport($psrClient, $reqFactory);

        // Assert
        $this->expectException(TransportException::class);

        // Act
        $transport->send(new HttpRequest('GET', 'https://x.com/test'));
    }

    /**
     * Verifies real HTTP operations with CurlTransport across GET, POST, custom verbs, and truncation.
     */
    public function testCurlTransportRealRequests(): void
    {
        // Arrange
        $port = 18991;
        $docRoot = realpath(__DIR__ . '/../../');
        $pid = exec("php -S 127.0.0.1:{$port} -t " . escapeshellarg($docRoot) . " > /dev/null 2>&1 & echo $!");
        usleep(250000);

        try {
            $transport = new CurlTransport(connectTimeout: 2);

            // Act & Assert (GET)
            $reqGet = new HttpRequest('GET', "http://127.0.0.1:{$port}/composer.json", [['Accept', 'application/json']]);
            $resGet = $transport->send($reqGet);
            $this->assertSame(200, $resGet->status);
            $this->assertFalse($resGet->truncated);
            $this->assertNotEmpty($resGet->body);

            // Act & Assert (POST)
            $reqPost = new HttpRequest('POST', "http://127.0.0.1:{$port}/composer.json");
            $resPost = $transport->send($reqPost);
            $this->assertSame(200, $resPost->status);

            // Act & Assert (Custom method PUT)
            $reqPut = new HttpRequest('PUT', "http://127.0.0.1:{$port}/composer.json");
            $resPut = $transport->send($reqPut);
            $this->assertSame(405, $resPut->status);

            // Act & Assert (Truncation)
            $reqTrunc = new HttpRequest('GET', "http://127.0.0.1:{$port}/composer.json", maxBodyBytes: 50);
            $resTrunc = $transport->send($reqTrunc);
            $this->assertTrue($resTrunc->truncated);
            $this->assertSame(50, strlen($resTrunc->body));
        } finally {
            // Cleanup
            exec("kill {$pid} 2>/dev/null");
        }
    }
}

