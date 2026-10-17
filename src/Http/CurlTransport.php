<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

use Mrpc\XScraper\Exception\TransportException;

/**
 * Built-in transport using PHP's cURL extension.
 *
 * Does not follow redirects or retry, respects timeouts and response size limits,
 * and maintains the exact header list order required for consistent browser fingerprints.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private int $connectTimeout = 10,
        private ?string $proxy = null,
    ) {
        if (!extension_loaded('curl')) {
            throw new \LogicException('ext-curl is required to use CurlTransport');
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('Failed to initialize cURL handle');
        }

        $headers = [];
        foreach ($request->headers as [$name, $value]) {
            $headers[] = "{$name}: {$value}";
        }

        $rawResponseHeaders = [];
        $responseBody = '';
        $truncated = false;
        $maxBytes = $request->maxBodyBytes;

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $request->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$rawResponseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $key = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if (isset($rawResponseHeaders[$key])) {
                        $rawResponseHeaders[$key] .= ', ' . $value;
                    } else {
                        $rawResponseHeaders[$key] = $value;
                    }
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$responseBody, &$truncated, $maxBytes): int {
                $dataLen = strlen($data);
                $currentLen = strlen($responseBody);
                if ($currentLen + $dataLen > $maxBytes) {
                    $remaining = $maxBytes - $currentLen;
                    if ($remaining > 0) {
                        $responseBody .= substr($data, 0, $remaining);
                    }
                    $truncated = true;
                    // Returning 0 causes CURLE_WRITE_ERROR to abort download early once cap is hit
                    return 0;
                }
                $responseBody .= $data;
                return $dataLen;
            },
        ];

        if ($request->method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        } elseif ($request->method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $request->method;
            if ($request->body !== null) {
                $options[CURLOPT_POSTFIELDS] = $request->body;
            }
        }

        if ($this->proxy !== null && $this->proxy !== '') {
            $options[CURLOPT_PROXY] = $this->proxy;
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            @curl_close($ch);
        }
        unset($ch);

        if (!$result && $errno !== 0 && !($errno === CURLE_WRITE_ERROR && $truncated)) {
            throw new TransportException("cURL error ({$errno}): {$error}");
        }

        return new HttpResponse(
            $statusCode,
            $rawResponseHeaders,
            $responseBody,
            $truncated,
        );
    }
}
