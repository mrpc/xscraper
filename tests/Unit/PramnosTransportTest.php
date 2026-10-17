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
    public function testSendGetSuccess(): void
    {
        $stub = new Client();
        $stub->response = new FakeResponse(200, ['Content-Type' => 'application/json', 'X-Custom' => 'val'], '{"foo":"bar"}', false);
        Client::$instance = $stub;

        $transport = new PramnosTransport(connectTimeout: 5, proxy: 'http://127.0.0.1:8080');

        $req = new HttpRequest('GET', 'https://x.com/api', [['accept', 'json']], timeout: 15, maxBodyBytes: 1024);
        $res = $transport->send($req);

        $this->assertSame(200, $res->status);
        $this->assertSame('{"foo":"bar"}', $res->body);
        $this->assertSame('val', $res->header('x-custom'));
        $this->assertFalse($res->truncated);

        $this->assertSame('GET', $stub->method);
        $this->assertSame('https://x.com/api', $stub->url);
        $this->assertSame(5, $stub->connectTimeout);
        $this->assertSame(15, $stub->timeout);
        $this->assertTrue($stub->withoutRedirectsCalled);
        $this->assertSame(1024, $stub->maxBytes);
    }

    public function testSendPostSuccess(): void
    {
        $stub = new Client();
        $stub->response = new FakeResponse(201, [], 'created');
        Client::$instance = $stub;

        $transport = new PramnosTransport();
        $req = new HttpRequest('POST', 'https://x.com/post');
        $res = $transport->send($req);

        $this->assertSame(201, $res->status);
        $this->assertSame('POST', $stub->method);
    }

    public function testSendUnsupportedMethodThrows(): void
    {
        $transport = new PramnosTransport();
        $req = new HttpRequest('DELETE', 'https://x.com/del');

        $this->expectException(InvalidArgumentException::class);
        $transport->send($req);
    }

    public function testSendClientExceptionThrowsTransportException(): void
    {
        $stub = new Client();
        $stub->throw = new ClientException('Connection timed out');
        Client::$instance = $stub;

        $transport = new PramnosTransport();
        $req = new HttpRequest('GET', 'https://x.com/fail');

        $this->expectException(TransportException::class);
        $transport->send($req);
    }
}
