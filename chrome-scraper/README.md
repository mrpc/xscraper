# @mrpc/chrome-scraper

Autonomous headless Chrome scraping microservice for **x.com** posts and conversation threads.

Designed to run in its own Docker container and communicate with the main PHP library (`mrpc/xscraper`) via **HTTP REST API** or **WebSocket streaming**.

---

## Features

- **Headless & Headed Chrome Automation:** Uses `puppeteer-core` with system Chromium. Supports automated login in visible (headed) or headless modes.
- **Automated Login & Session Persistence:** Automates x.com authentication, solves secondary challenges, extracts cookies (`auth_token`, `ct0`), and persists them in `/app/data/session.json`.
- **GraphQL Response Interception:** Intercepts real `TweetDetail` and `TweetResultByRestId` network responses directly from X's client-side app.
- **Dynamic Scrolling:** Automatically scrolls the thread to trigger reply loading until the requested limit is reached.
- **Auto Session Resolution:** Automatically uses stored session cookies if not passed explicitly in scrape requests.
- **Dual Communication Protocol:**
  - **REST API:** `POST /scrape`, `POST /login`, `GET /session`, `POST /logout`.
  - **WebSocket:** `ws://.../ws` for real-time progress and payload streaming.
- **DOM Fallback:** Extracts rendered tweet elements directly from the DOM as a backup mechanism.

---

## API Reference

### 1. Health & Session Check

```http
GET /health
```

**Response (200 OK):**
```json
{
  "status": "ok",
  "uptime": 120,
  "browserHealthy": true,
  "timestamp": "2026-10-18T12:00:00.000Z"
}
```

```http
GET /session
```

**Response (200 OK):**
```json
{
  "loggedIn": true,
  "username": "myuser",
  "savedAt": "2026-10-18T13:00:00.000Z",
  "hasAuthToken": true,
  "hasCt0": true
}
```

---

### 2. Automated or Headed Login

```http
POST /login
Content-Type: application/json
```

**Request Payload:**
```json
{
  "username": "my_x_username",
  "password": "my_x_password",
  "confirmation": "optional_email_or_phone_if_challenged",
  "headed": true,
  "timeoutMs": 120000
}
```

- When `"headed": true` is specified, Chrome launches with a visible window on the screen (`DISPLAY=:0`), allowing you to complete 2FA or Captchas if prompted.
- The session is saved to `/app/data/session.json` and reused automatically for subsequent scrapes.

---

### 3. Clear Stored Session

```http
POST /logout
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
