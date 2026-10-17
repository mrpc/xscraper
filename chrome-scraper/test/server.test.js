import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { extractPostId } from '../src/scraper.js';
import { startServer } from '../src/server.js';
import { WebSocket } from 'ws';

function waitForMessage(ws, predicate, timeout = 2000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      ws.off('message', onMsg);
      reject(new Error('Timeout waiting for message'));
    }, timeout);

    function onMsg(raw) {
      try {
        const msg = JSON.parse(raw.toString());
        if (predicate(msg)) {
          clearTimeout(timer);
          ws.off('message', onMsg);
          resolve(msg);
        }
      } catch {
        // ignore parse error
      }
    }
    ws.on('message', onMsg);
  });
}

describe('extractPostId', () => {
  test('extracts ID from full x.com status URL', () => {
    assert.equal(extractPostId('https://x.com/jack/status/20'), '20');
  });

  test('extracts ID from twitter.com status URL with query params', () => {
    assert.equal(
      extractPostId('https://twitter.com/user/statuses/1845187747970863384?s=20&t=xyz'),
      '1845187747970863384'
    );
  });

  test('accepts raw numeric ID string', () => {
    assert.equal(extractPostId('1845187747970863384'), '1845187747970863384');
    assert.equal(extractPostId('  1845187747970863384  '), '1845187747970863384');
  });

  test('throws on unparseable URL or invalid ID', () => {
    assert.throws(() => extractPostId('https://x.com/home'));
    assert.throws(() => extractPostId('not-a-valid-id'));
    assert.throws(() => extractPostId(''));
  });
});

describe('HTTP and WebSocket Server', () => {
  let instance;
  const testPort = 13999;
  const baseUrl = `http://127.0.0.1:${testPort}`;
  const wsUrl = `ws://127.0.0.1:${testPort}/ws`;

  before(() => {
    instance = startServer(testPort, '127.0.0.1');
  });

  after(() => {
    if (instance) {
      instance.wss.clients.forEach((c) => c.terminate());
      instance.wss.close();
      instance.server.closeAllConnections?.();
      instance.server.close();
    }
  });

  test('GET / returns API overview documentation', async () => {
    const res = await fetch(`${baseUrl}/`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.service, 'xscraper-chrome-service');
    assert.ok(body.endpoints['GET /health']);
    assert.ok(body.endpoints['POST /scrape']);
    assert.ok(body.endpoints['WS /ws']);
  });

  test('GET /health returns ok status', async () => {
    const res = await fetch(`${baseUrl}/health`);
    assert.equal(res.status, 200);
    const body = await res.json();
    assert.equal(body.status, 'ok');
    assert.ok(typeof body.uptime === 'number');
  });

  test('POST /scrape without URL returns 400', async () => {
    const res = await fetch(`${baseUrl}/scrape`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });
    assert.equal(res.status, 400);
    const body = await res.json();
    assert.equal(body.success, false);
    assert.ok(body.error);
  });

  test('WebSocket handles connect, ping-pong and invalid action', async () => {
    const ws = new WebSocket(wsUrl);
    const messages = [];

    ws.on('message', (raw) => {
      try {
        messages.push(JSON.parse(raw.toString()));
      } catch {}
    });

    await new Promise((resolve, reject) => {
      ws.on('open', resolve);
      ws.on('error', reject);
    });

    const waitMsg = async (predicate, maxMs = 2000) => {
      const start = Date.now();
      while (Date.now() - start < maxMs) {
        const found = messages.find(predicate);
        if (found) return found;
        await new Promise((r) => setTimeout(r, 20));
      }
      throw new Error('Timeout waiting for message');
    };

    // Expect connection message
    const connectedMsg = await waitMsg((m) => m.type === 'connected');
    assert.equal(connectedMsg.type, 'connected');

    // Send ping
    ws.send(JSON.stringify({ action: 'ping' }));
    const pongMsg = await waitMsg((m) => m.type === 'pong');
    assert.equal(pongMsg.type, 'pong');

    // Send unknown action
    ws.send(JSON.stringify({ action: 'unknown_test_action' }));
    const errorMsg = await waitMsg((m) => m.type === 'error' && m.error.includes('unknown_test_action'));
    assert.ok(errorMsg);

    // Send invalid scrape URL
    ws.send(JSON.stringify({ action: 'scrape', url: 'https://x.com/invalid' }));
    const parseErrorMsg = await waitMsg((m) => m.type === 'error' && m.error.includes('Could not parse post ID'));
    assert.ok(parseErrorMsg);

    ws.close();
  });
});
