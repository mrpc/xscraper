<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Unit;

use Mrpc\XScraper\Exception\TransportException;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\StreamTransport;
use PHPUnit\Framework\TestCase;

final class StreamTransportTest extends TestCase
{
    /**
     * Verifies that sending a request to an unreachable endpoint throws TransportException.
     */
    public function testSendThrowsTransportExceptionOnConnectionFailure(): void
    {
        // Arrange
        $transport = new StreamTransport(proxy: 'http://127.0.0.1:9');
        $request = new HttpRequest(
            'POST',
            'http://127.0.0.1:59999/non-existent-endpoint',
            [['Content-Type', 'application/json']],
            timeout: 1,
            body: '{"test":true}',
        );

        // Assert
        $this->expectException(TransportException::class);

        // Act
        $transport->send($request);
    }

    /**
     * Verifies successful response parsing with data streams.
     */
    public function testSendParsesResponseHeadersAndBody(): void
    {
        // Arrange
        $transport = new StreamTransport();
        $request = new HttpRequest(
            'GET',
            'data:text/plain;charset=utf-8,hello%20stream',
            [['Accept', 'text/plain']],
            timeout: 5,
        );

        // Act
        $response = $transport->send($request);

        // Assert
        $this->assertSame('hello stream', $response->body);
    }

    /**
     * Verifies parseResponseHeaders correctly extracts status and combines headers.
     */
    public function testParseResponseHeaders(): void
    {
        // Arrange
        $transport = new StreamTransport();
        $rawHeaders = [
            'HTTP/1.1 200 OK',
            'Content-Type: application/json; charset=utf-8',
            'Set-Cookie: session=abc',
            'Set-Cookie: token=xyz',
            'X-Custom-Header: value',
            'InvalidHeaderLineWithoutColon',
        ];

        // Act
        [$status, $headers] = $transport->parseResponseHeaders($rawHeaders);

        // Assert
        $this->assertSame(200, $status);
        $this->assertSame('application/json; charset=utf-8', $headers['content-type']);
        $this->assertSame('session=abc, token=xyz', $headers['set-cookie']);
        $this->assertSame('value', $headers['x-custom-header']);
    }
}
