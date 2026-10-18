import puppeteer from 'puppeteer-core';
import fs from 'node:fs';

let sharedBrowser = null;
let isLaunching = false;

const DEFAULT_CHROME_PATHS = [
  process.env.PUPPETEER_EXECUTABLE_PATH,
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/google-chrome',
  '/snap/bin/chromium',
].filter(Boolean);

export function findChromiumPath() {
  for (const p of DEFAULT_CHROME_PATHS) {
    if (fs.existsSync(p)) {
      return p;
    }
  }
  return process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium';
}

export async function getBrowser(customOptions = {}) {
  if (sharedBrowser && sharedBrowser.isConnected()) {
    return sharedBrowser;
  }

  if (isLaunching) {
    // Wait briefly if another request is launching the browser
    for (let i = 0; i < 50; i++) {
      await new Promise((r) => setTimeout(r, 100));
      if (sharedBrowser && sharedBrowser.isConnected()) {
        return sharedBrowser;
      }
    }
  }

  isLaunching = true;
  try {
    const executablePath = customOptions.executablePath || findChromiumPath();

    const isHeadless = customOptions.headless !== undefined ? customOptions.headless : true;
    const args = [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--disable-blink-features=AutomationControlled',
      '--no-first-run',
      '--no-zygote',
      '--lang=en-US,en',
      '--window-size=1280,800',
      ...(customOptions.args || []),
    ];

    if (isHeadless) {
      args.push('--disable-gpu', '--hide-scrollbars', '--mute-audio');
    }

    if (customOptions.proxy) {
      args.push(`--proxy-server=${customOptions.proxy}`);
    }

    sharedBrowser = await puppeteer.launch({
      executablePath,
      headless: isHeadless,
      ignoreDefaultArgs: ['--enable-automation'],
      args,
      defaultViewport: isHeadless ? { width: 1280, height: 800 } : null,
    });

    sharedBrowser.on('disconnected', () => {
      sharedBrowser = null;
    });

    return sharedBrowser;
  } finally {
    isLaunching = false;
  }
}

/**
 * Launches a separate, dedicated browser instance (e.g. for headed interactive login).
 *
 * @param {object} customOptions
 * @returns {Promise<import('puppeteer-core').Browser>}
 */
export async function launchDedicatedBrowser(customOptions = {}) {
  const executablePath = customOptions.executablePath || findChromiumPath();
  const isHeadless = customOptions.headless !== undefined ? customOptions.headless : false;

  const args = [
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled',
    '--no-first-run',
    '--lang=en-US,en',
    '--window-size=1280,850',
    ...(customOptions.args || []),
  ];

  if (isHeadless) {
    args.push('--disable-gpu', '--hide-scrollbars', '--mute-audio');
  }

  if (customOptions.proxy) {
    args.push(`--proxy-server=${customOptions.proxy}`);
  }

  return await puppeteer.launch({
    executablePath,
    headless: isHeadless,
    ignoreDefaultArgs: ['--enable-automation'],
    args,
    defaultViewport: isHeadless ? { width: 1280, height: 850 } : null,
  });
}



export async function closeBrowser() {
  if (sharedBrowser) {
    try {
      await sharedBrowser.close();
    } catch {
      // Ignore cleanup error
    }
    sharedBrowser = null;
  }
}

export function isBrowserHealthy() {
  return sharedBrowser !== null && sharedBrowser.isConnected();
}
