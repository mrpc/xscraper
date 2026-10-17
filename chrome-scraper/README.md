# @mrpc/chrome-scraper

Autonomous headless Chrome scraping microservice for **x.com** posts and conversation threads.

Designed to run in its own Docker container and communicate with the main PHP library (`mrpc/xscraper`) via **HTTP REST API** or **WebSocket streaming**.

---

## Features

- **Headless Chrome Automation:** Uses `puppeteer-core` with system Chromium to navigate and interact with x.com.
- **GraphQL Response Interception:** Intercepts real `TweetDetail` and `TweetResultByRestId` network responses directly from X's client-side app.
- **Dynamic Scrolling:** Automatically scrolls the thread to trigger reply loading until the requested limit is reached.
- **Cookie & Session Binding:** Supports `auth_token` and `ct0` session injection per scrape job inside isolated browser contexts.
- **Dual Communication Protocol:**
  - **REST API:** `POST /scrape` for synchronous request-response jobs.
  - **WebSocket:** `ws://.../ws` for real-time progress and payload streaming.
- **DOM Fallback:** Extracts rendered tweet elements directly from the DOM as a backup mechanism.

---

## API Reference

### 1. Health Check

```http
GET /health
```

**Response (200 OK):**
```json
{
  "status": "ok",
  "uptime": 120,
  "browserHealthy": true,
  "timestamp": "2026-10-17T12:00:00.000Z"
}
```

---

### 2. Scrape Post (HTTP REST)

```http
POST /scrape
Content-Type: application/json
```

**Request Payload:**
```json
{
  "url": "https://x.com/jack/status/20",
  "authToken": "optional_auth_token",
  "ct0": "optional_ct0",
  "maxScrolls": 6,
  "timeout": 30000,
  "proxy": "http://proxy.example.com:8080"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "tweetId": "20",
  "url": "https://x.com/i/status/20",
  "capturedGraphql": [
    { /* raw X GraphQL TweetDetail payload */ }
  ],
  "domTweets": [
    { "text": "just setting up my twttr", "author": "Jack Dorsey", "url": "https://x.com/jack/status/20" }
  ],
  "stats": {
    "capturedResponses": 1,
    "domTweetsCount": 1,
    "scrolls": 0,
    "durationMs": 2840
  }
}
```

---

### 3. WebSocket Streaming

Connect to `ws://localhost:3000/ws`.

**Client sends:**
```json
{
  "action": "scrape",
  "url": "https://x.com/jack/status/20",
  "authToken": "optional_auth_token",
  "ct0": "optional_ct0",
  "maxScrolls": 5
}
```

**Server streams events:**
1. `{"type": "init", "tweetId": "20", "targetUrl": "https://x.com/i/status/20"}`
2. `{"type": "navigating", "url": "..."}`
3. `{"type": "loaded", "targetUrl": "..."}`
4. `{"type": "graphql", "url": "...", "status": 200, "data": { ... }}`
5. `{"type": "scroll", "scrollIndex": 1, "maxScrolls": 5, "capturedCount": 1}`
6. `{"type": "complete", "result": { ... }}`

Ping-pong is also supported: send `{"action": "ping"}`, receive `{"type": "pong"}`.

---

## Running with Docker

Build and run standalone:

```bash
docker build -t xscraper-chrome-scraper .
docker run -p 3000:3000 xscraper-chrome-scraper
```

Or together with the main PHP library via Docker Compose:

```bash
docker compose up -d chrome-scraper
```

---

## Running Tests

Execute built-in Node.js tests:

```bash
npm test
```
