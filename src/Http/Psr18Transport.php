<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

use Mrpc\XScraper\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Transport for any PSR-18 client (Guzzle, Symfony HttpClient, ...).
 *
 * Configure the client itself not to follow redirects or retry, and set its proxy
 * there if you need one.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psr = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as [$name, $value]) {
            $psr = $psr->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($psr);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        $stream = $response->getBody();
        $body = $stream->read($request->maxBodyBytes + 1);
        while (!$stream->eof() && strlen($body) <= $request->maxBodyBytes) {
            $body .= $stream->read($request->maxBodyBytes + 1 - strlen($body));
        }
        $truncated = strlen($body) > $request->maxBodyBytes;

        return new HttpResponse(
            $response->getStatusCode(),
            $headers,
            $truncated ? substr($body, 0, $request->maxBodyBytes) : $body,
            $truncated,
        );
    }
}
