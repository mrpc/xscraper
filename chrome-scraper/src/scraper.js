import { getBrowser } from './browser.js';

export function extractPostId(urlOrId) {
  if (!urlOrId || typeof urlOrId !== 'string') {
    throw new Error('A valid post URL or numeric tweet ID is required');
  }
  const trimmed = urlOrId.trim();
  if (/^\d{1,25}$/.test(trimmed)) {
    return trimmed;
  }
  const match = trimmed.match(/\/status(?:es)?\/(\d{1,25})/);
  if (match) {
    return match[1];
  }
  throw new Error(`Could not parse post ID from input: "${urlOrId}"`);
}

export async function scrapePost(options = {}) {
  const startTime = Date.now();
  const postId = extractPostId(options.url || options.id);
  const targetUrl = `https://x.com/i/status/${postId}`;
  const timeout = options.timeout ?? 30000;
  const maxScrolls = options.maxScrolls ?? 8;
  const scrollDelay = options.scrollDelay ?? 1200;
  const onProgress = typeof options.onProgress === 'function' ? options.onProgress : null;

  if (onProgress) {
    onProgress({ type: 'init', tweetId: postId, targetUrl });
  }

  const browser = await getBrowser({
    proxy: options.proxy,
    executablePath: options.executablePath,
  });

  const context = await browser.createBrowserContext();
  const page = await context.newPage();

  const capturedGraphql = [];

  try {
    // Set viewport
    await page.setViewport({ width: 1920, height: 1080 });

    // Set Chromium user agent
    await page.setUserAgent(
      options.userAgent ||
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    );

    // Set auth cookies if provided
    const cookies = [];
    if (options.authToken) {
      cookies.push({
        name: 'auth_token',
        value: options.authToken,
        domain: '.x.com',
        path: '/',
        secure: true,
        httpOnly: true,
      });
    }
    if (options.ct0) {
      cookies.push({
        name: 'ct0',
        value: options.ct0,
        domain: '.x.com',
        path: '/',
        secure: true,
        httpOnly: false,
      });
    }
    if (cookies.length > 0) {
      await context.setCookie(...cookies);
    }

    // Intercept GraphQL network responses
    page.on('response', async (response) => {
      const reqUrl = response.url();
      if (
        reqUrl.includes('/graphql/') &&
        (reqUrl.includes('TweetDetail') || reqUrl.includes('TweetResultByRestId'))
      ) {
        try {
          const body = await response.json();
          capturedGraphql.push(body);
          if (onProgress) {
            onProgress({
              type: 'graphql',
              url: reqUrl,
              status: response.status(),
              data: body,
            });
          }
        } catch {
          // Non-JSON response or abort
        }
      }
    });

    if (onProgress) {
      onProgress({ type: 'navigating', url: targetUrl });
    }

    // Navigate to post page
    await page.goto(targetUrl, {
      waitUntil: 'domcontentloaded',
      timeout,
    });

    // Wait for tweet elements or timeout
    await page.waitForSelector('article[data-testid="tweet"]', { timeout: 8000 }).catch(() => {});

    if (onProgress) {
      onProgress({ type: 'loaded', targetUrl });
    }

    // Scroll to load replies if required
    let scrollsPerformed = 0;
    if (maxScrolls > 0) {
      for (let i = 0; i < maxScrolls; i++) {
        scrollsPerformed++;
        await page.evaluate(() => {
          window.scrollBy(0, 1000);
        });
        await new Promise((resolve) => setTimeout(resolve, scrollDelay));

        if (onProgress) {
          onProgress({
            type: 'scroll',
            scrollIndex: scrollsPerformed,
            maxScrolls,
            capturedCount: capturedGraphql.length,
          });
        }
      }
    }

    // Fallback: extract rendered tweets directly from DOM
    const domTweets = await page.evaluate(() => {
      const articles = document.querySelectorAll('article[data-testid="tweet"]');
      return Array.from(articles).map((art) => {
        const textEl = art.querySelector('div[data-testid="tweetText"]');
        const userEl = art.querySelector('div[data-testid="User-Name"]');
        const link = art.querySelector('a[href*="/status/"]');
        return {
          text: textEl ? textEl.innerText : '',
          author: userEl ? userEl.innerText.split('\n')[0] : '',
          url: link ? link.href : '',
        };
      });
    });

    const result = {
      success: true,
      tweetId: postId,
      url: targetUrl,
      capturedGraphql,
      domTweets,
      stats: {
        capturedResponses: capturedGraphql.length,
        domTweetsCount: domTweets.length,
        scrolls: scrollsPerformed,
        durationMs: Date.now() - startTime,
      },
    };

    if (onProgress) {
      onProgress({ type: 'complete', result });
    }

    return result;
  } finally {
    try {
      await page.close();
      await context.close();
    } catch {
      // Ignore cleanup error
    }
  }
}
