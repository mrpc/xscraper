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
  const timeoutMs = options.timeoutMs || 120000;
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

    onProgress({ event: 'status', stage: 'navigating', url: 'https://x.com/i/flow/login' });
    await page.goto('https://x.com/i/flow/login', {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
    });

    // Check if user is already authenticated
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

    // Automated interaction if username provided
    if (username) {
      onProgress({ event: 'status', stage: 'entering_username', username });
      const userInput = await page.waitForSelector(
        'input[autocomplete="username"], input[name="text"]',
        { timeout: 15000 }
      );

      if (userInput) {
        await userInput.click({ clickCount: 3 });
        await humanType(userInput, username);
        await new Promise((r) => setTimeout(r, 600));

        const nextBtn = await findButtonByText(page, 'Next');
        if (nextBtn) {
          await nextBtn.click();
        } else {
          await page.keyboard.press('Enter');
        }
      }

      await new Promise((r) => setTimeout(r, 2000));

      // Check for secondary confirmation challenge (phone, email, or handle)
      const challengeInput = await page.$(
        'input[data-testid="ocfEnterTextTextInput"], input[name="text"]'
      );
      if (challengeInput) {
        const isPasswordInput = await page.$('input[name="password"]');
        if (!isPasswordInput && confirmation) {
          onProgress({ event: 'status', stage: 'entering_confirmation', confirmation });
          await humanType(challengeInput, confirmation);
          await new Promise((r) => setTimeout(r, 500));
          const challengeNext = await findButtonByText(page, 'Next');
          if (challengeNext) {
            await challengeNext.click();
          } else {
            await page.keyboard.press('Enter');
          }
          await new Promise((r) => setTimeout(r, 2000));
        }
      }

      // Enter password if provided
      if (password) {
        onProgress({ event: 'status', stage: 'entering_password' });
        const passInput = await page.waitForSelector('input[name="password"]', { timeout: 15000 });
        if (passInput) {
          await humanType(passInput, password);
          await new Promise((r) => setTimeout(r, 600));

          const loginBtn = (await findButtonByText(page, 'Log in')) || (await findButtonByText(page, 'Next'));
          if (loginBtn) {
            await loginBtn.click();
          } else {
            await page.keyboard.press('Enter');
          }
        }
      }
    }

    onProgress({
      event: 'status',
      stage: 'waiting_for_authentication',
      message: isHeaded
        ? 'Please complete login / verification in the Chrome window if prompted'
        : 'Waiting for login completion cookies...',
    });

    // Poll for session cookies until timeout
    const startTime = Date.now();
    while (Date.now() - startTime < timeoutMs) {
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
