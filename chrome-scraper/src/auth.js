import { launchDedicatedBrowser, getBrowser } from './browser.js';
import { saveSession } from './session.js';

/**
 * Delays execution for a randomized duration to simulate human typing.
 *
 * @param {number} minMs
 * @param {number} maxMs
 * @returns {Promise<void>}
 */
function humanDelay(minMs = 40, maxMs = 90) {
  const ms = Math.floor(Math.random() * (maxMs - minMs + 1)) + minMs;
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Types text into an element with human-like delays.
 *
 * @param {import('puppeteer-core').ElementHandle} element
 * @param {string} text
 * @returns {Promise<void>}
 */
async function humanType(element, text) {
  for (const char of text) {
    await element.type(char);
    await humanDelay(25, 75);
  }
}

/**
 * Finds a button by its visible text content.
 *
 * @param {import('puppeteer-core').Page} page
 * @param {string} text
 * @returns {Promise<import('puppeteer-core').ElementHandle|null>}
 */
async function findButtonByText(page, text) {
  const buttons = await page.$$('button[role="button"], div[role="button"]');
  for (const btn of buttons) {
    const content = await page.evaluate((el) => el.innerText || el.textContent, btn);
    if (content && content.trim().toLowerCase().includes(text.toLowerCase())) {
      return btn;
    }
  }
  return null;
}

/**
 * Smoothly moves the mouse to a target coordinate using intermediate steps.
 *
 * @param {import('puppeteer-core').Page} page
 * @param {number} x
 * @param {number} y
 * @param {number} [steps=20]
 * @returns {Promise<void>}
 */
async function smoothMouseMove(page, x, y, steps = 20) {
  try {
    await page.mouse.move(x, y, { steps });
  } catch {
    // Ignore mouse errors if page is currently navigating
  }
}

/**
 * Dismisses unauthenticated popups/dialogs if present (e.g. "Don't miss what's happening").
 *
 * @param {import('puppeteer-core').Page} page
 * @returns {Promise<void>}
 */
async function dismissPopupsIfPresent(page) {
  try {
    await page.keyboard.press('Escape');
    const closeBtn =
      (await page.$('button[aria-label="Close"]')) ||
      (await page.$('div[aria-label="Close"]')) ||
      (await page.$('[data-testid="app-bar-close"]'));
    if (closeBtn) {
      await closeBtn.click().catch(() => {});
      await humanDelay(200, 400);
    }
  } catch {}
}

/**
 * Finds valid tweet detail links on the current page.
 *
 * @param {import('puppeteer-core').Page} page
 * @returns {Promise<import('puppeteer-core').ElementHandle[]>}
 */
async function findTweetLinks(page) {
  try {
    const links = await page.$$('article[data-testid="tweet"] a[href*="/status/"]');
    const valid = [];
    for (const link of links) {
      const href = await page.evaluate((el) => el.getAttribute('href'), link);
      if (href && /\/[A-Za-z0-9_]+\/status\/[0-9]+$/.test(href.split('?')[0])) {
        valid.push(link);
      }
    }
    return valid;
  } catch {
    return [];
  }
}

/**
 * Finds valid user profile links from tweets on the current page.
 *
 * @param {import('puppeteer-core').Page} page
 * @returns {Promise<import('puppeteer-core').ElementHandle[]>}
 */
async function findAccountLinks(page) {
  try {
    const links = await page.$$('article[data-testid="tweet"] a[role="link"][href^="/"]');
    const valid = [];
    const seen = new Set();
    const reserved = new Set([
      'home', 'explore', 'notifications', 'messages', 'i', 'search', 'settings',
      'login', 'logout', 'signup', 'tos', 'privacy', 'x',
    ]);

    for (const link of links) {
      const href = await page.evaluate((el) => el.getAttribute('href'), link);
      if (!href) continue;
      const clean = href.split('?')[0].replace(/^\//, '');
      if (/^[A-Za-z0-9_]{1,15}$/.test(clean) && !reserved.has(clean.toLowerCase())) {
        if (!seen.has(clean.toLowerCase())) {
          seen.add(clean.toLowerCase());
          valid.push(link);
        }
      }
    }
    return valid;
  } catch {
    return [];
  }
}

/**
 * Simulates human-like browsing on public X pages (viewing tweets, opening threads, visiting accounts).
 *
 * @param {import('puppeteer-core').Page} page
 * @param {number} durationSeconds Total duration to browse
 * @param {function} onProgress Progress callback
 * @returns {Promise<void>}
 */
export async function simulateHumanBrowsing(page, durationSeconds, onProgress) {
  if (durationSeconds <= 0) {
    return;
  }

  const startTime = Date.now();
  const totalMs = durationSeconds * 1000;

  onProgress({
    event: 'status',
    stage: 'warming_up',
    message: `Starting natural browsing simulation (${durationSeconds}s) exploring tweets and accounts...`,
  });

  try {
    await page.goto('https://x.com/x', {
      waitUntil: 'domcontentloaded',
      timeout: 35000,
    });
  } catch {}

  await humanDelay(2000, 3500);
  await dismissPopupsIfPresent(page);

  let scrollCount = 0;
  let lastActionTime = Date.now();

  while (Date.now() - startTime < totalMs) {
    if (page.isClosed()) {
      break;
    }

    const elapsed = Math.round((Date.now() - startTime) / 1000);
    const remaining = Math.max(0, durationSeconds - elapsed);

    if (remaining < 6) {
      await humanDelay(1000, 2500);
      break;
    }

    const currentUrl = page.url();
    const isStatusDetail = currentUrl.includes('/status/');
    const timeSinceLastAction = (Date.now() - lastActionTime) / 1000;

    // Case 1: If we opened a tweet detail thread and have spent >= 8 seconds reading replies
    if (isStatusDetail && timeSinceLastAction >= 8) {
      onProgress({
        event: 'status',
        stage: 'warming_up',
        message: `Finished reading thread, returning to feed (${elapsed}s / ${durationSeconds}s, ${remaining}s left)...`,
      });

      lastActionTime = Date.now();
      try {
        await page.goBack({ timeout: 15000 });
        await humanDelay(2000, 3500);
        await dismissPopupsIfPresent(page);
      } catch {
        try {
          await page.goto('https://x.com/x', { waitUntil: 'domcontentloaded', timeout: 25000 });
        } catch {}
      }
      continue;
    }

    // Case 2: On a feed or profile, decide whether to open a random tweet or account
    if (!isStatusDetail && timeSinceLastAction >= 10 && remaining > 15) {
      const chooseTweet = Math.random() < 0.6;

      if (chooseTweet) {
        const tweetLinks = await findTweetLinks(page);
        if (tweetLinks.length > 0) {
          const idx = Math.floor(Math.random() * Math.min(tweetLinks.length, 5));
          const targetLink = tweetLinks[idx];
          const href = await page.evaluate((el) => el.getAttribute('href'), targetLink);

          try {
            await page.evaluate((el) => {
              el.removeAttribute('target');
              el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, targetLink);
            await humanDelay(500, 1000);

            const box = await targetLink.boundingBox();
            if (box) {
              await smoothMouseMove(page, box.x + box.width / 2, box.y + box.height / 2, 18);
              await humanDelay(300, 600);
            }

            onProgress({
              event: 'status',
              stage: 'warming_up',
              message: `Opening tweet ${href ? href.slice(0, 38) : ''} to view thread and replies (${elapsed}s / ${durationSeconds}s)...`,
            });

            lastActionTime = Date.now();
            await targetLink.click();
            await humanDelay(2500, 4500);
            await dismissPopupsIfPresent(page);
            continue;
          } catch {}
        }
      } else {
        const accountLinks = await findAccountLinks(page);
        if (accountLinks.length > 0) {
          const idx = Math.floor(Math.random() * Math.min(accountLinks.length, 5));
          const targetLink = accountLinks[idx];
          const href = await page.evaluate((el) => el.getAttribute('href'), targetLink);

          try {
            await page.evaluate((el) => {
              el.removeAttribute('target');
              el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, targetLink);
            await humanDelay(500, 1000);

            const box = await targetLink.boundingBox();
            if (box) {
              await smoothMouseMove(page, box.x + box.width / 2, box.y + box.height / 2, 18);
              await humanDelay(300, 600);
            }

            const cleanHandle = href ? href.replace(/^\//, '') : 'user';
            onProgress({
              event: 'status',
              stage: 'warming_up',
              message: `Visiting account profile @${cleanHandle} from timeline (${elapsed}s / ${durationSeconds}s)...`,
            });

            lastActionTime = Date.now();
            await targetLink.click();
            await humanDelay(2500, 4500);
            await dismissPopupsIfPresent(page);
            continue;
          } catch {}
        }
      }
    }

    // Default action: inspect visible tweets, read, and scroll
    let tweetSnippet = '';
    let hoveredTweet = false;

    try {
      const tweets = await page.$$('article[data-testid="tweet"]');
      if (tweets.length > 0) {
        const idx = Math.floor(Math.random() * Math.min(tweets.length, 4));
        const tweet = tweets[idx];
        const box = await tweet.boundingBox();

        if (box && box.y >= 0 && box.y < 800) {
          const targetX = Math.max(100, Math.min(750, box.x + box.width * (0.3 + Math.random() * 0.4)));
          const targetY = Math.max(100, Math.min(700, box.y + box.height * (0.3 + Math.random() * 0.4)));

          await smoothMouseMove(page, targetX, targetY, 15 + Math.floor(Math.random() * 15));

          tweetSnippet = await page.evaluate((el) => {
            const textEl = el.querySelector('[data-testid="tweetText"]');
            return textEl ? (textEl.innerText || textEl.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 50) : '';
          }, tweet);

          hoveredTweet = true;
        }
      }
    } catch {}

    const pageContext = isStatusDetail ? 'replies' : 'timeline';
    if (hoveredTweet && tweetSnippet) {
      onProgress({
        event: 'status',
        stage: 'warming_up',
        message: `Viewing post: "${tweetSnippet}..." (${elapsed}s / ${durationSeconds}s)`,
      });
    } else {
      onProgress({
        event: 'status',
        stage: 'warming_up',
        message: `Browsing ${pageContext} (${elapsed}s / ${durationSeconds}s, ${remaining}s left)...`,
      });
    }

    // Reading pause (2.5 to 5.5 seconds) with subtle cursor drift
    const readPause = Math.floor(Math.random() * 2500) + 2500;
    const pauseStart = Date.now();
    while (Date.now() - pauseStart < readPause && Date.now() - startTime < totalMs) {
      await humanDelay(800, 1400);
      try {
        const currentCoord = await page.evaluate(() => ({
          x: Math.floor(Math.random() * 300) + 200,
          y: Math.floor(Math.random() * 300) + 200,
        }));
        await smoothMouseMove(page, currentCoord.x, currentCoord.y, 8);
      } catch {}
    }

    if (Date.now() - startTime >= totalMs) {
      break;
    }

    scrollCount++;
    const shouldScrollUp = scrollCount % 5 === 0;

    try {
      if (shouldScrollUp) {
        const upDistance = -(Math.floor(Math.random() * 180) + 120);
        await page.evaluate((dist) => window.scrollBy({ top: dist, behavior: 'smooth' }), upDistance);
        await humanDelay(1200, 2000);
      } else {
        const downDistance = Math.floor(Math.random() * 260) + 260; // 260px - 520px
        await page.evaluate((dist) => window.scrollBy({ top: dist, behavior: 'smooth' }), downDistance);
        await humanDelay(1400, 2500);
      }
    } catch {}

    await dismissPopupsIfPresent(page);
  }

  onProgress({
    event: 'status',
    stage: 'warming_up_completed',
    message: 'Warmup completed! Opening login flow...',
  });
}

/**
 * Executes an automated login flow on x.com.
 *
 * @param {object} options
 * @param {string} [options.username]
 * @param {string} [options.password]
 * @param {string} [options.confirmation] Secondary verification (email or phone)
 * @param {boolean} [options.headed] Launch visible browser (defaults to true if display available)
 * @param {number} [options.timeoutMs] Maximum time to wait for login completion (default 120s)
 * @param {string} [options.proxy]
 * @param {function} [options.onProgress] Progress event callback
 * @returns {Promise<{ success: boolean, username?: string, authToken?: string, ct0?: string }>}
 */
export async function performLogin(options = {}) {
  const username = options.username || process.env.X_USERNAME;
  const password = options.password || process.env.X_PASSWORD;
  const confirmation = options.confirmation || process.env.X_EMAIL || process.env.X_PHONE;
  const isHeaded = options.headed !== undefined
    ? options.headed
    : Boolean(process.env.DISPLAY || process.env.WAYLAND_DISPLAY);
  const warmupSeconds = options.warmupSeconds !== undefined
    ? Math.max(0, Number(options.warmupSeconds))
    : (options.warmup !== false && isHeaded ? 80 : 0);
  const timeoutMs = options.timeoutMs || (isHeaded ? Math.max(300000, (warmupSeconds + 200) * 1000) : 120000);
  const onProgress = typeof options.onProgress === 'function' ? options.onProgress : () => {};

  onProgress({ event: 'status', stage: 'launching_browser', headed: isHeaded });

  const browser = isHeaded
    ? await launchDedicatedBrowser({ headless: false, proxy: options.proxy })
    : await getBrowser({ headless: true, proxy: options.proxy });

  let page;
  let context = null;

  if (isHeaded) {
    const existingPages = await browser.pages();
    page = existingPages.length > 0 ? existingPages[0] : await browser.newPage();
  } else {
    context = await browser.createBrowserContext();
    page = await context.newPage();
  }

  try {
    await page.setUserAgent(
      'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36'
    );

    await page.setExtraHTTPHeaders({
      'Accept-Language': 'en-US,en;q=0.9',
    });

    // Only mask navigator.webdriver if present without modifying native plugins/chrome objects
    await page.evaluateOnNewDocument(() => {
      delete Object.getPrototypeOf(navigator).webdriver;
    });

    let fatalSecurityError = null;

    // Monitor onboarding and security/fraud API network responses (including jf.x.com)
    page.on('response', async (res) => {
      const url = res.url();
      const isMonitored =
        url.includes('/onboarding/') ||
        url.includes('jf.x.com') ||
        url.includes('/auth/') ||
        url.includes('/login');

      if (!isMonitored) {
        return;
      }

      try {
        const status = res.status();
        let subtasks = null;
        let errors = null;

        try {
          const json = await res.json();
          if (json.subtasks) {
            subtasks = json.subtasks.map((s) => s.subtask_id).join(', ');
          }
          if (json.errors && json.errors.length > 0) {
            errors = json.errors.map((e) => (typeof e === 'string' ? e : e.message || JSON.stringify(e))).join(' | ');
          }
        } catch {
          // If response is not standard JSON (e.g. binary-framed jf.x.com responses), extract text
          try {
            const raw = await res.text();
            if (raw.includes('Sorry, you are not allowed') || raw.includes('temporarily limited') || raw.includes('error') || status >= 400) {
              const match = raw.match(/Sorry,[^!.\r\n]+[.!]/i) || raw.match(/temporarily limited[^!.\r\n]+[.!]/i) || raw.match(/errors[0-9]*([^!.\r\n]+[.!])/i);
              errors = match ? (match[1] || match[0]).trim() : `Security check failed (HTTP ${status})`;
            }
          } catch {}
        }

        if (subtasks) {
          console.log(`[x-login-api] Status ${status} - Subtasks: [${subtasks}]`);
          onProgress({
            event: 'api',
            stage: 'x_api_step',
            status,
            subtasks,
          });
        }

        if (errors) {
          console.log(`[x-login-api] Error: ${errors}`);
          if (
            errors.includes('not allowed to log in') ||
            errors.includes('temporarily limited') ||
            errors.includes('Suspended')
          ) {
            fatalSecurityError = errors;
          }
          onProgress({
            event: 'api_error',
            stage: 'x_api_error',
            status,
            errors,
          });
        }
      } catch {
        // Ignore response inspection errors
      }
    });

    page.on('console', (msg) => {
      const text = msg.text();
      if (text.includes('Arkose') || text.includes('captcha') || text.includes('error')) {
        onProgress({
          event: 'browser_log',
          stage: 'browser_console',
          message: text.slice(0, 150),
        });
      }
    });

    page.on('pageerror', (err) => {
      console.log(`[browser-pageerror] ${err.message}`);
    });


    // Check if user is already authenticated from persistent profile before warmup
    let cookies = await page.cookies();
    let authTokenCookie = cookies.find((c) => c.name === 'auth_token');
    let ct0Cookie = cookies.find((c) => c.name === 'ct0');

    if (authTokenCookie && ct0Cookie) {
      onProgress({ event: 'status', stage: 'already_authenticated' });
      saveSession({
        authToken: authTokenCookie.value,
        ct0: ct0Cookie.value,
        username: username || 'authenticated_user',
        cookies,
      });
      return {
        success: true,
        username,
        authToken: authTokenCookie.value,
        ct0: ct0Cookie.value,
      };
    }

    // Organic browsing warmup simulation (1-2 minutes browsing tweets on public feeds)
    if (warmupSeconds > 0) {
      await simulateHumanBrowsing(page, warmupSeconds, onProgress);
    }

    // Re-check if authenticated after warmup
    cookies = await page.cookies();
    authTokenCookie = cookies.find((c) => c.name === 'auth_token');
    ct0Cookie = cookies.find((c) => c.name === 'ct0');

    if (authTokenCookie && ct0Cookie) {
      onProgress({ event: 'status', stage: 'already_authenticated' });
      saveSession({
        authToken: authTokenCookie.value,
        ct0: ct0Cookie.value,
        username: username || 'authenticated_user',
        cookies,
      });
      return {
        success: true,
        username,
        authToken: authTokenCookie.value,
        ct0: ct0Cookie.value,
      };
    }

    onProgress({ event: 'status', stage: 'opening_login', message: 'Opening login dialog...' });
    let loginOpened = false;

    if (warmupSeconds > 0 && page.url().includes('x.com')) {
      try {
        const signInBtn =
          (await page.$('a[href="/login"]')) ||
          (await page.$('a[href="/i/flow/login"]')) ||
          (await page.$('[data-testid="loginButton"]')) ||
          (await findButtonByText(page, 'Sign in')) ||
          (await findButtonByText(page, 'Log in'));

        if (signInBtn) {
          const btnBox = await signInBtn.boundingBox();
          if (btnBox) {
            await smoothMouseMove(page, btnBox.x + btnBox.width / 2, btnBox.y + btnBox.height / 2, 15);
            await humanDelay(300, 700);
          }
          await signInBtn.click();
          loginOpened = true;
          await new Promise((r) => setTimeout(r, 2500));
        }
      } catch {
        // Fallback to direct navigation
      }
    }

    if (!loginOpened || !page.url().includes('login')) {
      await page.goto('https://x.com/i/flow/login', {
        waitUntil: 'domcontentloaded',
        timeout: 45000,
      });
    }

    onProgress({
      event: 'status',
      stage: 'waiting_for_authentication',
      message: isHeaded
        ? 'Please complete login / verification in the Chrome window if prompted'
        : 'Waiting for login completion cookies...',
    });

    // Concurrently handle automated inputs and poll for session cookies in parallel
    let usernameEntered = false;
    let confirmationEntered = false;
    let passwordEntered = false;

    const startTime = Date.now();
    while (Date.now() - startTime < timeoutMs) {
      if (browser && typeof browser.isConnected === 'function' && !browser.isConnected()) {
        throw new Error('Browser window was closed before login completed.');
      }
      if (page && typeof page.isClosed === 'function' && page.isClosed()) {
        throw new Error('Login tab was closed before login completed.');
      }

      // Check for authenticated session cookies
      cookies = await page.cookies();
      authTokenCookie = cookies.find((c) => c.name === 'auth_token');
      ct0Cookie = cookies.find((c) => c.name === 'ct0');

      if (authTokenCookie && ct0Cookie) {
        onProgress({ event: 'status', stage: 'authenticated_successfully' });
        saveSession({
          authToken: authTokenCookie.value,
          ct0: ct0Cookie.value,
          username: username || 'authenticated_user',
          cookies,
        });

        return {
          success: true,
          username,
          authToken: authTokenCookie.value,
          ct0: ct0Cookie.value,
        };
      }
 
      if (fatalSecurityError) {
        throw new Error(`X security check failed: ${fatalSecurityError}`);
      }

      // Automated assistance for input fields (runs safely in parallel with user)
      try {
        // 1. Initial login input: Username / Email
        if (username && !usernameEntered) {
          const userInput = await page.$(
            'input[autocomplete="username"], input[name="text"]'
          );
          if (userInput) {
            const isPasswordPresent = await page.$('input[name="password"]');
            if (!isPasswordPresent) {
              onProgress({ event: 'status', stage: 'entering_username', username });
              await userInput.click({ clickCount: 3 });
              await humanType(userInput, username);
              await humanDelay(300, 600);

              const nextBtn =
                (await findButtonByText(page, 'Next')) ||
                (await findButtonByText(page, 'Continue')) ||
                (await findButtonByText(page, 'Log in'));
              if (nextBtn) {
                await nextBtn.click();
              } else {
                await page.keyboard.press('Enter');
              }
              usernameEntered = true;
              await humanDelay(1000, 2000);
            }
          }
        }

        // 2. Secondary challenge: "Enter your phone number or username"
        const challengeInput = await page.$(
          'input[data-testid="ocfEnterTextTextInput"], input[name="text"]'
        );
        if (challengeInput) {
          const isPasswordPresent = await page.$('input[name="password"]');
          if (!isPasswordPresent) {
            if (confirmation && !confirmationEntered) {
              onProgress({ event: 'status', stage: 'entering_confirmation', confirmation });
              await challengeInput.click({ clickCount: 3 });
              await humanType(challengeInput, confirmation);
              await humanDelay(300, 600);

              const nextBtn =
                (await findButtonByText(page, 'Next')) ||
                (await findButtonByText(page, 'Continue')) ||
                (await findButtonByText(page, 'Log in'));
              if (nextBtn) {
                await nextBtn.click();
              } else {
                await page.keyboard.press('Enter');
              }
              confirmationEntered = true;
              await humanDelay(1000, 2000);
            } else if (!confirmationEntered) {
              onProgress({
                event: 'status',
                stage: 'waiting_for_user_verification',
                message: 'Secondary verification required (phone/username). Please enter it in the browser window or pass --confirmation.',
              });
              confirmationEntered = true;
            }
          }
        }

        // 3. Password input
        const passInput = await page.$('input[name="password"]');
        if (passInput) {
          if (password && !passwordEntered) {
            onProgress({ event: 'status', stage: 'entering_password' });
            await passInput.click({ clickCount: 3 });
            await humanType(passInput, password);
            await humanDelay(300, 600);

            const loginBtn =
              (await findButtonByText(page, 'Log in')) ||
              (await findButtonByText(page, 'Next'));
            if (loginBtn) {
              await loginBtn.click();
            } else {
              await page.keyboard.press('Enter');
            }
            passwordEntered = true;
            await humanDelay(1000, 2000);
          } else if (!passwordEntered) {
            onProgress({
              event: 'status',
              stage: 'waiting_for_password',
              message: 'Password prompt detected. Please enter password in the browser window.',
            });
            passwordEntered = true;
          }
        }
      } catch {
        // Automation step error (e.g. element detached while typing) should not break loop
      }

      await new Promise((r) => setTimeout(r, 1000));
    }

    throw new Error(`Login timed out after ${Math.round(timeoutMs / 1000)} seconds without establishing an authenticated session.`);
  } finally {
    try {
      if (isHeaded) {
        await browser.close();
      } else {
        await page.close();
        if (context) {
          await context.close();
        }
      }
    } catch {
      // Ignore cleanup error
    }
  }

}
