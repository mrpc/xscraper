<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

use Mrpc\XScraper\Exception\TransportException;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;

/**
 * Transport built on PramnosFramework's Pramnos\Http\Client.
 *
 * Retries are never enabled and redirects are not followed. Responses are size-capped.
 *
 * Proxy: Pramnos\Http\Client has no proxy option, but libcurl underneath honours the
 * standard https_proxy / HTTPS_PROXY environment variables. {@see withProxy()} sets
 * that variable for the process when a proxy is configured.
 */
final class PramnosTransport implements Transport
{
    public function __construct(private int $connectTimeout = 10, ?string $proxy = null)
    {
        if (!class_exists(Client::class)) {
            throw new \LogicException('mrpc/pramnosframework is not installed');
        }
        if ($proxy !== null) {
            self::withProxy($proxy);
        }
    }

    /** Route this process's libcurl traffic through $proxy (e.g. "http://user:pass@host:port"). */
    public static function withProxy(string $proxy): void
    {
        if (!preg_match('~^(https?|socks5h?)://[^\s]+$~i', $proxy)) {
            throw new \InvalidArgumentException('Proxy must look like scheme://[user:pass@]host:port');
        }
        putenv('https_proxy=' . $proxy);
        putenv('HTTPS_PROXY=' . $proxy);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $client = match ($request->method) {
            'GET'  => Client::get($request->url),
            'POST' => Client::post($request->url),
            default => throw new \InvalidArgumentException("Unsupported method {$request->method}"),
        };

        try {
            $response = $client
                ->headers($request->headerMap())
                ->connectTimeout($this->connectTimeout)
                ->timeout($request->timeout)
                ->withoutRedirects()
                ->maxResponseBytes($request->maxBodyBytes)
                ->send();
        } catch (ClientException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return new HttpResponse(
            $response->status(),
            array_change_key_case($response->headers(), CASE_LOWER),
            $response->body(),
            $response->truncated(),
        );
    }
}
