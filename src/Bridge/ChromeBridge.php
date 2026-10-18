<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Bridge;

use Mrpc\XScraper\Exception\ResponseException;
use Mrpc\XScraper\Exception\TransportException;
use Mrpc\XScraper\Exception\TweetNotFoundException;
use Mrpc\XScraper\Http\CurlTransport;
use Mrpc\XScraper\Http\HttpRequest;
use Mrpc\XScraper\Http\PramnosTransport;
use Mrpc\XScraper\Http\StreamTransport;
use Mrpc\XScraper\Http\Transport;
use Mrpc\XScraper\Model\Conversation;
use Mrpc\XScraper\Model\FetchStats;
use Mrpc\XScraper\Model\Tweet;
use Mrpc\XScraper\Model\User;
use Mrpc\XScraper\Session;
use Mrpc\XScraper\Snowflake;
use Mrpc\XScraper\TimelineParser;
use Pramnos\Http\Client as PramnosHttpClient;

/**
 * Client bridge communicating with the autonomous Node.js headless Chrome scraping service.
 *
 * Dispatches scraping tasks over HTTP REST or streams events, transforming intercepted
 * GraphQL TweetDetail payloads into strongly-typed Conversation and Tweet models.
 */
class ChromeBridge
{
    private string $serviceUrl;
    private Transport $transport;
    private TimelineParser $parser;

    public function __construct(
        string $serviceUrl = 'http://127.0.0.1:3000',
        ?Transport $transport = null,
        ?TimelineParser $parser = null,
    ) {
        $this->serviceUrl = rtrim($serviceUrl, '/');
        $this->parser = $parser ?? new TimelineParser();

        if ($transport !== null) {
            $this->transport = $transport;
        } elseif (class_exists(PramnosHttpClient::class)) {
            $this->transport = new PramnosTransport();
        } elseif (extension_loaded('curl')) {
            $this->transport = new CurlTransport();
        } else {
            $this->transport = new StreamTransport();
        }
    }


    public function serviceUrl(): string
    {
        return $this->serviceUrl;
    }

    /**
     * Checks if the headless Chrome service is running and healthy.
     */
    public function isHealthy(): bool
    {
        try {
            $request = new HttpRequest('GET', $this->serviceUrl . '/health', [
                ['Accept', 'application/json'],
            ], timeout: 5);

            $response = $this->transport->send($request);
            if ($response->status !== 200) {
                return false;
            }

            $json = $response->json();
            return is_array($json) && ($json['status'] ?? '') === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Triggers an automated or headed login flow on x.com via the Chrome scraper.
     *
     * @param string|null $username Username, email or handle
     * @param string|null $password Account password
     * @param string|null $confirmation Secondary verification (phone or email)
     * @param bool|null $headed Force headed (visible browser) or headless mode
     * @param int $timeout Timeout in seconds
     * @param string|null $proxy Outbound proxy
     * @return array<string,mixed>
     *
     * @throws ResponseException on login failure
     */
    /**
     * Triggers an automated or headed login flow on x.com via the Chrome scraper.
     *
     * @param string|null $username Username, email or handle
     * @param string|null $password Account password
     * @param string|null $confirmation Secondary verification (phone or email)
     * @param bool|null $headed Force headed (visible browser) or headless mode
     * @param int $timeout Timeout in seconds
     * @param string|null $proxy Outbound proxy
     * @param callable|null $onProgress Optional callback for real-time progress events
     * @return array<string,mixed>
     *
     * @throws ResponseException on login failure
     */
    public function login(
        ?string $username = null,
        ?string $password = null,
        ?string $confirmation = null,
        ?bool $headed = null,
        int $timeout = 120,
        ?string $proxy = null,
        ?callable $onProgress = null,
    ): array {
        if ($onProgress !== null) {
            return $this->loginWithStream(
                $username,
                $password,
                $confirmation,
                $headed,
                $timeout,
                $proxy,
                $onProgress,
            );
        }

        $payload = [
            'username' => $username,
            'password' => $password,
            'confirmation' => $confirmation,
            'headed' => $headed,
            'timeoutMs' => max(10, $timeout) * 1000,
            'proxy' => $proxy,
        ];

        $request = new HttpRequest(
            'POST',
            $this->serviceUrl . '/login',
            [
                ['Content-Type', 'application/json'],
                ['Accept', 'application/json'],
            ],
            body: json_encode($payload, JSON_THROW_ON_ERROR),
            timeout: $timeout + 10,
        );

        $response = $this->transport->send($request);
        $json = $response->json();

        if ($response->status !== 200 || !is_array($json) || !($json['success'] ?? false)) {
            $msg = is_array($json) && isset($json['error'])
                ? (string) $json['error']
                : "Login failed with HTTP {$response->status}";
            throw new ResponseException($msg);
        }

        return $json;
    }

    /**
     * Streams real-time login events and status from the Chrome microservice.
     *
     * @param callable|null $onProgress
     * @return array<string,mixed>
     */
    public function loginWithStream(
        ?string $username = null,
        ?string $password = null,
        ?string $confirmation = null,
        ?bool $headed = null,
        int $timeout = 300,
        ?string $proxy = null,
        ?callable $onProgress = null,
    ): array {
        $payload = [
            'username' => $username,
            'password' => $password,
            'confirmation' => $confirmation,
            'headed' => $headed,
            'timeoutMs' => max(10, $timeout) * 1000,
            'proxy' => $proxy,
            'stream' => true,
        ];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $url = $this->serviceUrl . '/login?stream=1';

        $httpOptions = [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/x-ndjson\r\n",
            'content' => $encoded,
            'timeout' => $timeout + 15,
            'ignore_errors' => true,
        ];

        if ($proxy !== null && $proxy !== '') {
            $httpOptions['proxy'] = $proxy;
            $httpOptions['request_fulluri'] = true;
        }

        $context = stream_context_create(['http' => $httpOptions]);
        $fp = @fopen($url, 'rb', false, $context);
        if ($fp === false) {
            return $this->login($username, $password, $confirmation, $headed, $timeout, $proxy);
        }

        @stream_set_read_buffer($fp, 0);
        @stream_set_chunk_size($fp, 1);

        $linesGenerator = (function () use ($fp) {
            try {
                while (!feof($fp)) {
                    $line = fgets($fp);
                    if ($line !== false) {
                        yield $line;
                    }
                }
            } finally {
                fclose($fp);
            }
        })();

        return $this->parseStreamLines($linesGenerator, $onProgress);
    }

    /**
     * @param iterable<mixed> $lines
     * @param callable|null $onProgress
     * @return array<string,mixed>
     */
    public function parseStreamLines(iterable $lines, ?callable $onProgress = null): array
    {
        $lastResult = null;
        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }
            $data = json_decode(trim($line), true);
            if (!is_array($data)) {
                continue;
            }

            if ($onProgress !== null) {
                $onProgress($data);
            }

            if (isset($data['type']) && ($data['type'] === 'complete' || $data['type'] === 'error')) {
                $lastResult = $data;
            }
        }

        if ($lastResult === null) {
            throw new ResponseException('Connection closed without completing login');
        }

        if (!($lastResult['success'] ?? false)) {
            $err = $lastResult['error'] ?? 'Login failed';
            throw new ResponseException((string) $err);
        }

        return $lastResult;
    }



    /**
     * Checks the currently stored session status on the Chrome scraper.
     *
     * @return array<string,mixed>|null
     */
    public function getSession(): ?array
    {
        try {
            $request = new HttpRequest(
                'GET',
                $this->serviceUrl . '/session',
                [['Accept', 'application/json']],
                timeout: 5,
            );
            $response = $this->transport->send($request);
            if ($response->status !== 200) {
                return null;
            }
            $json = $response->json();
            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Clears the stored session on the Chrome scraper.
     */
    public function logout(): bool
    {
        try {
            $request = new HttpRequest(
                'POST',
                $this->serviceUrl . '/logout',
                [['Accept', 'application/json']],
                timeout: 5,
            );
            $response = $this->transport->send($request);
            $json = $response->json();
            return $response->status === 200 && is_array($json) && ($json['success'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }


    /**
     * Delegates scraping of a post and its replies to the headless Chrome service.
     *
     * @throws TweetNotFoundException if tweet could not be found or extracted
     * @throws ResponseException on unexpected response from chrome service
     * @throws TransportException on network or connectivity failure
     */
    public function scrape(
        string $postIdOrUrl,
        ?Session $session = null,
        int $maxScrolls = 6,
        int $timeout = 30,
        ?string $proxy = null,
    ): Conversation {
        $tweetId = Snowflake::parse($postIdOrUrl);

        $payload = [
            'id' => $tweetId,
            'url' => 'https://x.com/i/status/' . $tweetId,
            'authToken' => $session?->authToken,
            'ct0' => $session?->ct0,
            'maxScrolls' => max(0, $maxScrolls),
            'timeout' => max(5, $timeout) * 1000,
            'proxy' => $proxy,
        ];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = new HttpRequest(
            'POST',
            $this->serviceUrl . '/scrape',
            [
                ['Content-Type', 'application/json'],
                ['Accept', 'application/json'],
            ],
            body: $encoded,
            timeout: $timeout + 10,
        );

        $response = $this->transport->send($request);
        if ($response->status < 200 || $response->status >= 300) {
            $json = $response->json();
            $msg = is_array($json) && isset($json['error'])
                ? (string) $json['error']
                : "Chrome scraper service returned HTTP {$response->status}";

            if ($response->status === 404) {
                throw new TweetNotFoundException($tweetId, $msg);
            }
            throw new ResponseException($msg);
        }

        $result = $response->json();
        if (!is_array($result) || !($result['success'] ?? false)) {
            $msg = is_array($result) && isset($result['error']) ? (string) $result['error'] : 'Scraping failed';
            throw new ResponseException($msg);
        }

        return $this->buildConversationFromResult($tweetId, $result);
    }

    /**
     * Reconstructs a Conversation model from intercepted GraphQL payloads or DOM fallbacks.
     *
     * @param array<string,mixed> $result
     */
    private function buildConversationFromResult(string $tweetId, array $result): Conversation
    {
        $capturedGraphql = $result['capturedGraphql'] ?? [];
        $focalTweet = null;
        $repliesMap = [];

        if (is_array($capturedGraphql)) {
            foreach ($capturedGraphql as $graphqlPayload) {
                if (!is_array($graphqlPayload)) {
                    continue;
                }
                $parsed = $this->parser->parseTweetDetail($graphqlPayload, $tweetId);
                if ($focalTweet === null && $parsed->focalTweet !== null) {
                    $focalTweet = $parsed->focalTweet;
                }
                foreach ($parsed->replies as $reply) {
                    $repliesMap[$reply->id] = $reply;
                }
            }
        }

        // DOM Fallback if no GraphQL payload was intercepted
        if ($focalTweet === null) {
            $domTweets = $result['domTweets'] ?? [];
            if (is_array($domTweets) && !empty($domTweets)) {
                $first = $domTweets[0];
                $focalTweet = new Tweet(
                    id: $tweetId,
                    conversationId: $tweetId,
                    inReplyToId: null,
                    createdAt: null,
                    text: (string) ($first['text'] ?? ''),
                    lang: null,
                    user: new User('0', (string) ($first['author'] ?? 'unknown'), (string) ($first['author'] ?? 'Unknown')),
                );
            }
        }

        if ($focalTweet === null) {
            throw new TweetNotFoundException($tweetId, "Could not extract tweet {$tweetId} from Chrome scraper result");
        }

        $durationMs = isset($result['stats']['durationMs']) ? (int) $result['stats']['durationMs'] : 0;
        $stats = new FetchStats(
            requests: 1,
            pages: is_array($capturedGraphql) ? count($capturedGraphql) : 1,
            newReplies: count($repliesMap),
            fromCache: false,
            coalesced: false,
            fetchedAt: time(),
        );

        return new Conversation($focalTweet, array_values($repliesMap), $stats);
    }
}
