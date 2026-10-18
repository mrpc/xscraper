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
  const timeoutMs = options.timeoutMs || (isHeaded ? 300000 : 120000);
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


    onProgress({ event: 'status', stage: 'navigating', url: 'https://x.com/i/flow/login' });
    await page.goto('https://x.com/i/flow/login', {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
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
