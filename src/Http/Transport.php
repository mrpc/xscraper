<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

/**
 * Sends one HTTP request. Implementations must not retry or follow redirects on their
 * own: every request X sees should be one the library decided to make.
 */
interface Transport
{
    /** @throws \Mrpc\XScraper\Exception\TransportException on network-level failure */
    public function send(HttpRequest $request): HttpResponse;
}
