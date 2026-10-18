<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

use Mrpc\XScraper\Exception\TransportException;

/**
 * Pure PHP stream-based HTTP transport using stream contexts.
 *
 * Serves as a zero-dependency fallback when ext-curl or Pramnos HTTP client
 * is not installed in the running PHP environment.
 */
final class StreamTransport implements Transport
{
    public function __construct(
        private ?string $proxy = null,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $headersStr = '';
        foreach ($request->headers as [$name, $val]) {
            $headersStr .= "{$name}: {$val}\r\n";
        }

        $httpOptions = [
            'method' => $request->method,
            'header' => $headersStr,
            'timeout' => $request->timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ];

        if ($request->body !== null) {
            $httpOptions['content'] = $request->body;
        }

        if ($this->proxy !== null && $this->proxy !== '') {
            $httpOptions['proxy'] = $this->proxy;
            $httpOptions['request_fulluri'] = true;
        }

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $fp = @fopen($request->url, 'rb', false, $context);
        if ($fp === false) {
            $error = error_get_last();
            $msg = $error['message'] ?? 'Could not open stream';
            throw new TransportException("StreamTransport error: {$msg}");
        }

        $meta = stream_get_meta_data($fp);
        $wrapperData = $meta['wrapper_data'] ?? [];

        $body = stream_get_contents($fp, $request->maxBodyBytes);
        fclose($fp);

        if ($body === false) {
            throw new TransportException('Failed to read response body from stream');
        }

        [$status, $responseHeaders] = $this->parseResponseHeaders($wrapperData);

        return new HttpResponse($status, $responseHeaders, $body);
    }

    /**
     * @param array<int|string,mixed> $wrapperData
     * @return array{0: int, 1: array<string,string>}
     */
    public function parseResponseHeaders(array $wrapperData): array
    {
        $status = 0;
        $responseHeaders = [];

        foreach ($wrapperData as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $matches)) {
                $status = (int) $matches[1];
                continue;
            }
            if (is_string($line)) {
                $colonPos = strpos($line, ':');
                if ($colonPos !== false) {
                    $headerName = strtolower(trim(substr($line, 0, $colonPos)));
                    $headerVal = trim(substr($line, $colonPos + 1));
                    if (isset($responseHeaders[$headerName])) {
                        $responseHeaders[$headerName] .= ', ' . $headerVal;
                    } else {
                        $responseHeaders[$headerName] = $headerVal;
                    }
                }
            }
        }

        return [$status, $responseHeaders];
    }
}

