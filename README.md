# mrpc/xscraper

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](composer.json)
[![Code Coverage](https://img.shields.io/badge/coverage-%3E%3D95%25-brightgreen.svg)](phpunit.xml)

A request-frugal PHP library and CLI tool for reading posts and replies from **x.com** without the official API.

Designed for high-efficiency operations:
- **Incremental Pagination:** Stops scanning immediately when an already known reply is encountered and merges updates with cached state.
- **Age-Adaptive Snowflake TTLs:** Calculates post age directly from the tweet's 64-bit Snowflake ID without network requests. Active threads refresh quickly, while older discussions stay cached longer.
- **Single-Flight Locking:** Prevents duplicate concurrent requests across multiple PHP worker processes using cross-process mutex locks (`flock`).
- **Shared Request Throttle:** Cross-process pacing with randomized jitter (1.5s - 4.5s), tracking `x-rate-limit-remaining` headers and applying a 15-minute session cooldown on HTTP 429.
- **Consistent Browser Fingerprints:** Maintains strict Chromium header order, fetch metadata, and dynamic CSRF token binding (`ct0`).
- **Pluggable Transports & Cache:** Built-in adapters for `Pramnos\Http\Client` and `Pramnos\Cache\Cache` (`mrpc/pramnosframework`), any PSR-18 client, PSR-16 cache, or native `ext-curl` with local file cache.
- **Outbound Proxy Support:** Works seamlessly with HTTP, HTTPS, and SOCKS5 proxies.

---

## Requirements

- PHP 8.1 or higher
- `ext-json`
- `psr/simple-cache` (PSR-16)

Optional:
- `ext-curl` (for built-in `CurlTransport`)
- `mrpc/pramnosframework` (for native `PramnosTransport` and `PramnosCache`)
- `psr/http-client` and `psr/http-factory` (for `Psr18Transport`)

---

## Installation

```bash
composer require mrpc/xscraper
```

---

## Authentication & Sessions

X requires two session cookies from an authenticated browser session:
1. `auth_token`: The authentication session token.
2. `ct0`: The CSRF token (sent as `x-csrf-token`).

### Session Configuration

```php
use Mrpc\XScraper\Session;

// Direct instantiation
$session = new Session(
    authToken: 'YOUR_AUTH_TOKEN',
    ct0: 'YOUR_CT0',
    name: 'account_1',
);

// Or parsed from a browser Cookie header
$session = Session::fromCookieHeader('auth_token=...; ct0=...; ...');

// Or loaded from environment variables (X_AUTH_TOKEN, X_CT0 or X_COOKIES)
$session = Session::fromEnvironment();
```

---

## Library Usage

### Quick Start

```php
use Mrpc\XScraper\FetchMode;
use Mrpc\XScraper\Session;
use Mrpc\XScraper\XScraper;

$session = Session::fromEnvironment();

// Create scraper with persistent cache, locks and throttle state
// When running in PramnosFramework, Pramnos\Cache\Cache and Pramnos\Http\Client are auto-detected
$scraper = XScraper::create(
    session: $session,
    storageDir: '/var/lib/xscraper', // optional custom directory
    proxy: 'http://proxy.example.com:8080', // optional proxy
    // cache: \Pramnos\Cache\Cache::getInstance(), // optional explicit Pramnos cache
);

// Fetch conversation with smart incremental pagination
$conversation = $scraper->getConversation(
    'https://x.com/username/status/1845187747970863384',
    mode: FetchMode::Smart,
    maxReplies: 50,
);

// Focal tweet
$tweet = $conversation->tweet;
echo "Author: @{$tweet->user->screenName} ({$tweet->user->name})\n";
echo "Posted: {$tweet->createdAt?->format('Y-m-d H:i')}\n";
echo "Text: {$tweet->text}\n";
echo "Metrics: {$tweet->likes} likes, {$tweet->retweets} reposts, {$tweet->replies} replies\n";

// Media attachments
foreach ($tweet->media as $media) {
    echo "Media [{$media->type}]: {$media->url}\n";
}

// Access replies
echo "\nReplies (" . $conversation->replyCount() . "):\n";
foreach ($conversation->directReplies() as $reply) {
    echo "- @{$reply->user->screenName}: {$reply->text}\n";
}

// Fetch performance stats
$stats = $conversation->stats;
echo "\nRequests sent: {$stats->requests}\n";
echo "Pages fetched: {$stats->pages}\n";
echo "Served from cache: " . ($stats->fromCache ? 'Yes' : 'No') . "\n";
```

### Fetch Strategies (`FetchMode`)

- **`FetchMode::Smart`** *(Default)*: Returns cached data if still fresh. When stale, fetches only the newest page(s) until a previously known reply is encountered, merging new replies into the stored conversation.
- **`FetchMode::CacheOnly`**: Serves strictly from local cache without touching the network. Throws `TweetNotFoundException` if not yet cached.
- **`FetchMode::Refresh`**: Forces an incremental check for new replies regardless of cache freshness, merging any new replies with the existing conversation.
- **`FetchMode::Full`**: Discards stored conversation state and re-fetches the entire thread from scratch.

### Single Tweet Lookup

If you only need the focal post without reply scanning:

```php
$tweet = $scraper->getTweet('1845187747970863384');
```

---

## CLI Usage

The package includes a standalone CLI binary: `bin/xscraper`.

### Setup Environment

Set credentials in your environment:

```bash
export X_AUTH_TOKEN="your_auth_token"
export X_CT0="your_ct0"
```

### Commands & Options

```bash
# Fetch and print formatted thread
bin/xscraper get https://x.com/jack/status/20

# Output as JSON
bin/xscraper get 1845187747970863384 --json

# Save JSON directly to a file
bin/xscraper get 1845187747970863384 --json --output=thread.json

# Fetch using CacheOnly mode
bin/xscraper get 1845187747970863384 --mode=cache

# Force refresh with higher replies limit
bin/xscraper get 1845187747970863384 --mode=refresh --max-replies=100

# Route through outbound proxy
bin/xscraper get 1845187747970863384 --proxy="socks5://127.0.0.1:9050"

# Delegate scraping to autonomous Headless Chrome microservice
bin/xscraper get 1845187747970863384 --chrome
bin/xscraper get 1845187747970863384 --chrome=http://127.0.0.1:3000
```

---

## Headless Chrome Scraping Microservice (`chrome-scraper`)

The repository includes a secondary, autonomous system located in [`chrome-scraper/`](chrome-scraper/): a standalone Node.js microservice running headless Chromium via Puppeteer.

### Architecture & Capabilities

```
+-------------------------------------------------------+
|                 mrpc/xscraper (PHP)                   |
|                                                       |
|  XScraper CLI / App  --->  ChromeBridge               |
+---------------------------------+---------------------+
                                  |
                                  | HTTP POST /scrape
                                  | WebSocket /ws (live progress)
                                  v
+-------------------------------------------------------+
|             chrome-scraper (Node.js Service)          |
|                                                       |
|  Express REST API + WebSocket Server (:3000)          |
|                       |                               |
|                       v                               |
|  Puppeteer Headless Chromium Engine                   |
|    - Isolated BrowserContext per scrape request       |
|    - Session cookie injection (auth_token, ct0)       |
|    - GraphQL network interception (TweetDetail)       |
|    - Dynamic DOM scrolling & rich rendering fallback  |
+-------------------------------------------------------+
```

1. **Autonomous Operation:** Runs independently in its own Docker container (`chrome-scraper`), isolating memory and browser subprocesses.
2. **Dual Extraction Strategy:**
   - **GraphQL Network Interception:** Listens to X's internal GraphQL `TweetDetail` network requests directly within Chromium and extracts the raw typed payloads.
   - **DOM Extraction Fallback:** If GraphQL payloads are blocked or omitted, automatically falls back to scraping rendered DOM `article[data-testid="tweet"]` nodes.
3. **Bidirectional Communication:**
   - **REST API:** `POST /scrape` accepting `{ url, authToken, ct0, maxScrolls, timeoutMs }`.
   - **WebSocket Streaming:** `GET /ws` providing real-time streaming progress events (`status`, `scrolled`, `intercepted`, `done`, `error`) to connected systems.
   - **Health Check:** `GET /health` with Chromium process status and memory usage.

### Running with Docker Compose

Start both systems together:

```bash
docker compose up -d
```

The PHP environment can communicate directly with the Chrome scraper via the internal docker network at `http://chrome-scraper:3000`.

### PHP Bridge Usage

```php
use Mrpc\XScraper\Bridge\ChromeBridge;

$bridge = new ChromeBridge(
    serviceUrl: 'http://127.0.0.1:3000',
    authToken: getenv('X_AUTH_TOKEN'),
    ct0: getenv('X_CT0'),
);

// Check if headless chrome service is healthy
if ($bridge->isHealthy()) {
    $conversation = $bridge->scrapeConversation('https://x.com/jack/status/20');
    echo "Focal Tweet: " . $conversation->tweet->text . "\n";
    echo "Replies: " . $conversation->replyCount() . "\n";
}
```

---

## Docker & Testing

The repository includes a complete Docker setup matching PramnosFramework conventions.

### Running Tests

Execute unit and integration tests inside Docker:

```bash
# Run tests with code coverage check (enforces >= 95%)
./dockertest

# Run tests fast without code coverage
./dockertest --nocoverage

# Run with testdox format
./dockertest --testdox
```

### Running Node.js Microservice Tests

```bash
cd chrome-scraper
npm test
```

### Code Coverage Standard

The test suite enforces a strict code coverage standard of **>= 95%** statement coverage across the library. If code coverage drops below 95%, `./dockertest` exits with a non-zero status.

---

## Architecture & Components

| Component | Class | Description |
|---|---|---|
| **Client** | `Mrpc\XScraper\XScraper` | Central coordinator orchestrating Cache, Mutex, Throttle, and Parser |
| **Chrome Bridge** | `Mrpc\XScraper\Bridge\ChromeBridge` | HTTP client bridge integrating the autonomous headless Chrome scraper |
| **Model** | `Mrpc\XScraper\Model\Conversation` | Focal post and reply collection with tree helpers |
| **Model** | `Mrpc\XScraper\Model\Tweet` | Immutable post model with media, links, hashtags, and metrics |
| **Parser** | `Mrpc\XScraper\TimelineParser` | GraphQL `TweetDetail` response parser with cursor tracking |
| **Resolver** | `Mrpc\XScraper\QueryIdResolver` | GraphQL query ID management, feature flags, and auto-discovery |
| **Lock** | `Mrpc\XScraper\Lock\FileLockFactory` | Cross-process mutual exclusion (`flock`) for single-flight fetching |
| **Throttle** | `Mrpc\XScraper\RateLimit\Throttle` | Shared rate pacing with random jitter, server header tracking & 429 cooldown |
| **Cache** | `Mrpc\XScraper\Cache\PramnosCache` | PSR-16 cache adapter wrapping native `Pramnos\Cache\Cache` |
| **Cache** | `Mrpc\XScraper\Cache\FileCache` | PSR-16 atomic JSON file-based cache engine |
| **Cache** | `Mrpc\XScraper\Cache\TtlPolicy` | Age-adaptive TTL policy calculated via Snowflake ID |
| **Transport**| `Mrpc\XScraper\Http\Transport` | HTTP abstraction (`PramnosTransport`, `Psr18Transport`, `CurlTransport`) |

---

## License

MIT License. See [LICENSE](LICENSE) for details.
