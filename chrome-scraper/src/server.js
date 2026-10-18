import express from 'express';
import { createServer } from 'node:http';
import { WebSocketServer, WebSocket } from 'ws';
import { isBrowserHealthy, closeBrowser } from './browser.js';
import { scrapePost, extractPostId } from './scraper.js';
import { performLogin } from './auth.js';
import { getSessionStatus, clearSession, saveSession } from './session.js';

export function createApp() {
  const app = express();
  app.use(express.json({ limit: '2mb' }));

  // Root documentation endpoint
  app.get('/', (_req, res) => {
    res.json({
      service: 'xscraper-chrome-service',
      version: '1.0.0',
      description: 'Autonomous Headless Chrome Scraping Service for x.com',
      endpoints: {
        'GET /health': 'Health check status',
        'POST /scrape': 'Scrape a post or conversation (JSON body: url, authToken, ct0, maxScrolls, timeout)',
        'POST /login': 'Automated or headed login to x.com (JSON body: username, password, confirmation, headed, timeoutMs)',
        'GET /session': 'Check currently stored session status',
        'POST /logout': 'Clear stored session',
        'WS /ws': 'WebSocket streaming endpoint for live scraping progress and events',
      },
    });
  });

  // Health check endpoint
  app.get('/health', (_req, res) => {
    const healthy = isBrowserHealthy();
    res.json({
      status: 'ok',
      uptime: Math.round(process.uptime()),
      browserHealthy: healthy,
      timestamp: new Date().toISOString(),
    });
  });

  // Session status endpoint
  app.get('/session', (_req, res) => {
    const status = getSessionStatus();
    res.json(status);
  });

  // Set session endpoint (allows direct cookie import)
  app.post('/session', (req, res) => {
    const { authToken, ct0, username, cookies } = req.body || {};
    if (!authToken || !ct0) {
      return res.status(400).json({ error: 'authToken and ct0 are required' });
    }
    const success = saveSession({ authToken, ct0, username, cookies });
    res.json({ success, message: success ? 'Session saved' : 'Failed to save session' });
  });

  // Logout endpoint
  app.post('/logout', (_req, res) => {
    const success = clearSession();
    res.json({ success, message: success ? 'Session cleared' : 'No session to clear' });
  });

  // Login endpoint with optional streaming support
  app.post('/login', async (req, res) => {
    const { username, password, confirmation, headed, timeoutMs, proxy, warmupSeconds, warmup } = req.body || {};
    const isStream = req.query.stream === '1' || req.body?.stream === true;

    if (isStream) {
      res.setHeader('Content-Type', 'application/x-ndjson; charset=utf-8');
      res.setHeader('Cache-Control', 'no-cache');
      res.flushHeaders?.();

      const sendChunk = (data) => {
        try {
          res.write(JSON.stringify(data) + '\n');
        } catch {}
      };

      try {
        const result = await performLogin({
          username,
          password,
          confirmation,
          headed,
          timeoutMs,
          proxy,
          warmupSeconds,
          warmup,
          onProgress: (evt) => {
            sendChunk({ type: 'progress', ...evt });
          },
        });

        sendChunk({
          type: 'complete',
          success: true,
          message: 'Logged in successfully',
          username: result.username,
          hasAuthToken: Boolean(result.authToken),
          hasCt0: Boolean(result.ct0),
        });
        return res.end();
      } catch (err) {
        sendChunk({
          type: 'error',
          success: false,
          error: err.message || 'Login failed',
        });
        return res.end();
      }
    }

    try {
      const result = await performLogin({
        username,
        password,
        confirmation,
        headed,
        timeoutMs,
        proxy,
        warmupSeconds,
        warmup,
      });

      return res.json({
        success: true,
        message: 'Logged in successfully',
        username: result.username,
        hasAuthToken: Boolean(result.authToken),
        hasCt0: Boolean(result.ct0),
      });
    } catch (err) {
      return res.status(401).json({
        success: false,
        error: err.message || 'Login failed',
      });
    }
  });


  // HTTP Scrape endpoint
  app.post('/scrape', async (req, res) => {
    const { url, id, authToken, ct0, maxScrolls, timeout, proxy, userAgent } = req.body || {};

    try {
      extractPostId(url || id);
    } catch (err) {
      return res.status(400).json({
        success: false,
        error: err.message,
      });
    }

    try {
      const result = await scrapePost({
        url,
        id,
        authToken,
        ct0,
        maxScrolls: typeof maxScrolls === 'number' ? maxScrolls : 6,
        timeout: typeof timeout === 'number' ? timeout : 30000,
        proxy,
        userAgent,
      });

      return res.json(result);
    } catch (err) {
      return res.status(500).json({
        success: false,
        error: err.message || 'Scraping failed',
      });
    }
  });

  return app;
}


export function startServer(port = 3000, host = '0.0.0.0') {
  const app = createApp();
  const server = createServer(app);
  const wss = new WebSocketServer({ server, path: '/ws' });

  wss.on('connection', (ws) => {
    ws.send(JSON.stringify({ type: 'connected', message: 'WebSocket ready for scrape jobs' }));

    ws.on('message', async (data) => {
      let message;
      try {
        message = JSON.parse(data.toString());
      } catch {
        return ws.send(JSON.stringify({ type: 'error', error: 'Invalid JSON payload' }));
      }

      if (message.action === 'ping') {
        return ws.send(JSON.stringify({ type: 'pong', timestamp: Date.now() }));
      }

      if (message.action === 'scrape') {
        try {
          extractPostId(message.url || message.id);
        } catch (err) {
          return ws.send(JSON.stringify({ type: 'error', error: err.message }));
        }

        try {
          await scrapePost({
            ...message,
            onProgress: (evt) => {
              if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify(evt));
              }
            },
          });
        } catch (err) {
          if (ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'error', error: err.message }));
          }
        }
        return;
      }

      if (message.action === 'login') {
        try {
          const res = await performLogin({
            ...message,
            onProgress: (evt) => {
              if (ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify(evt));
              }
            },
          });
          if (ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'login_complete', result: res }));
          }
        } catch (err) {
          if (ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'error', error: err.message }));
          }
        }
        return;
      }

      ws.send(JSON.stringify({ type: 'error', error: `Unknown action: "${message.action}"` }));

    });
  });

  const runningServer = server.listen(port, host, () => {
    console.log(`[chrome-scraper] Service listening on http://${host}:${port}`);
    console.log(`[chrome-scraper] WebSocket stream available at ws://${host}:${port}/ws`);
  });

  const shutdown = async () => {
    console.log('[chrome-scraper] Shutting down gracefully...');
    wss.close();
    runningServer.close();
    await closeBrowser();
    process.exit(0);
  };

  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);

  return { server: runningServer, wss, app };
}
