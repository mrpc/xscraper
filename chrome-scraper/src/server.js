import express from 'express';
import { createServer } from 'node:http';
import { WebSocketServer, WebSocket } from 'ws';
import { isBrowserHealthy, closeBrowser } from './browser.js';
import { scrapePost, extractPostId } from './scraper.js';

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
