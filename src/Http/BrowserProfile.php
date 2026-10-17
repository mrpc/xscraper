<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Http;

/**
 * A single, consistent browser identity: one User-Agent with the client-hint and
 * fetch-metadata headers that belong to it, emitted in the order Chrome sends them
 * for a same-origin XHR on x.com.
 *
 * Keep one profile per session. A real user does not change browsers between
 * requests, and a session whose User-Agent keeps changing is easier to flag, not harder.
 */
final class BrowserProfile
{
    /**
     * @param array<string,string> $clientHints sec-ch-ua* headers (empty for non-Chromium browsers)
     */
    public function __construct(
        public readonly string $userAgent,
        public readonly array $clientHints = [],
        public readonly string $acceptLanguage = 'en-US,en;q=0.9',
    ) {
    }

    public static function chromeLinux(): self
    {
        return new self(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            [
                'sec-ch-ua'          => '"Google Chrome";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
                'sec-ch-ua-mobile'   => '?0',
                'sec-ch-ua-platform' => '"Linux"',
            ],
        );
    }

    public static function chromeWindows(): self
    {
        return new self(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            [
                'sec-ch-ua'          => '"Google Chrome";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
                'sec-ch-ua-mobile'   => '?0',
                'sec-ch-ua-platform' => '"Windows"',
            ],
        );
    }

    /**
     * Ordered headers for a GraphQL call.
     *
     * @return list<array{0:string,1:string}>
     */
    public function apiHeaders(string $bearer, string $ct0, string $authToken, string $referer): array
    {
        $h = [];
        foreach ($this->clientHints as $name => $value) {
            if ($name === 'sec-ch-ua-platform') {
                continue; // Chrome sends platform after the auth headers
            }
            $h[] = [$name, $value];
        }
        $h[] = ['x-csrf-token', $ct0];
        $h[] = ['x-twitter-client-language', 'en'];
        $h[] = ['x-twitter-active-user', 'yes'];
        $h[] = ['x-twitter-auth-type', 'OAuth2Session'];
        $h[] = ['authorization', 'Bearer ' . $bearer];
        $h[] = ['user-agent', $this->userAgent];
        $h[] = ['content-type', 'application/json'];
        if (isset($this->clientHints['sec-ch-ua-platform'])) {
            $h[] = ['sec-ch-ua-platform', $this->clientHints['sec-ch-ua-platform']];
        }
        $h[] = ['accept', '*/*'];
        $h[] = ['sec-fetch-site', 'same-origin'];
        $h[] = ['sec-fetch-mode', 'cors'];
        $h[] = ['sec-fetch-dest', 'empty'];
        $h[] = ['referer', $referer];
        $h[] = ['accept-language', $this->acceptLanguage];
        $h[] = ['cookie', 'auth_token=' . $authToken . '; ct0=' . $ct0];
        return $h;
    }

    /**
     * Ordered headers for loading a page or script (used only for query-id discovery).
     *
     * @return list<array{0:string,1:string}>
     */
    public function documentHeaders(string $ct0, string $authToken, bool $script = false): array
    {
        $h = [];
        foreach ($this->clientHints as $name => $value) {
            $h[] = [$name, $value];
        }
        $h[] = ['user-agent', $this->userAgent];
        $h[] = ['accept', $script ? '*/*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
        $h[] = ['sec-fetch-site', $script ? 'cross-site' : 'none'];
        $h[] = ['sec-fetch-mode', $script ? 'cors' : 'navigate'];
        $h[] = ['sec-fetch-dest', $script ? 'script' : 'document'];
        if ($script) {
            $h[] = ['referer', 'https://x.com/'];
        }
        $h[] = ['accept-language', $this->acceptLanguage];
        if (!$script) {
            $h[] = ['cookie', 'auth_token=' . $authToken . '; ct0=' . $ct0];
        }
        return $h;
    }
}
