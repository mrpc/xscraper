<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

require_once __DIR__ . '/../Support/PramnosStub.php';

use InvalidArgumentException;
use Mrpc\XScraper\Exception\TransportException;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\PramnosTransport;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\FakeResponse;

final class PramnosTransportTest extends TestCase
{
    /**
     * Verifies successful dispatch of a GET request via Pramnos\Http\Client with custom headers and options.
     */
    public function testSendGetSuccess(): void
    {
        // Arrange
        $stub = new Client();
        $stub->response = new FakeResponse(200, ['Content-Type' => 'application/json', 'X-Custom' => 'val'], '{"foo":"bar"}', false);
        Client::$instance = $stub;

        $transport = new PramnosTransport(connectTimeout: 5, proxy: 'http://127.0.0.1:8080');
        $req = new HttpRequest('GET', 'https://x.com/api', [['accept', 'json']], timeout: 15, maxBodyBytes: 1024);

        // Act
        $res = $transport->send($req);

        // Assert
        $this->assertSame(200, $res->status);
        $this->assertSame('{"foo":"bar"}', $res->body);
        $this->assertSame('val', $res->header('x-custom'));
        $this->assertFalse($res->truncated);

        // Verify that options were forwarded correctly to the underlying Pramnos client
        $this->assertSame('GET', $stub->method);
        $this->assertSame('https://x.com/api', $stub->url);
        $this->assertSame(5, $stub->connectTimeout);
        $this->assertSame(15, $stub->timeout);
        $this->assertTrue($stub->withoutRedirectsCalled);
        $this->assertSame(1024, $stub->maxBytes);
    }

    /**
     * Verifies that POST requests configure the client method accordingly.
     */
    public function testSendPostSuccess(): void
    {
        // Arrange
        $stub = new Client();
        $stub->response = new FakeResponse(201, [], 'created');
        Client::$instance = $stub;

        $transport = new PramnosTransport();
        $req = new HttpRequest('POST', 'https://x.com/post');

        // Act
        $res = $transport->send($req);

        // Assert
        $this->assertSame(201, $res->status);
        $this->assertSame('POST', $stub->method);
    }

    /**
     * Verifies that unsupported HTTP methods throw an InvalidArgumentException.
     */
    public function testSendUnsupportedMethodThrows(): void
    {
        // Arrange
        $transport = new PramnosTransport();
        $req = new HttpRequest('DELETE', 'https://x.com/del');

        // Assert & Act
        $this->expectException(InvalidArgumentException::class);
        $transport->send($req);
    }

    /**
     * Verifies that Pramnos ClientException is caught and translated to TransportException.
     */
    public function testSendClientExceptionThrowsTransportException(): void
    {
        // Arrange
        $stub = new Client();
        $stub->throw = new ClientException('Connection timed out');
        Client::$instance = $stub;

        $transport = new PramnosTransport();
        $req = new HttpRequest('GET', 'https://x.com/fail');

        // Assert & Act
        $this->expectException(TransportException::class);
        $transport->send($req);
    }
}
