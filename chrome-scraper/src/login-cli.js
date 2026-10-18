#!/usr/bin/env node

import { performLogin } from './auth.js';

const args = process.argv.slice(2);
let headed = undefined;
let username = process.env.X_USERNAME;
let password = process.env.X_PASSWORD;
let confirmation = process.env.X_EMAIL || process.env.X_PHONE;

for (const arg of args) {
  if (arg === '--headed' || arg === '--gui') {
    headed = true;
  } else if (arg === '--headless') {
    headed = false;
  } else if (arg.startsWith('--username=')) {
    username = arg.slice(11);
  } else if (arg.startsWith('--password=')) {
    password = arg.slice(11);
  } else if (arg.startsWith('--confirmation=') || arg.startsWith('--email=')) {
    confirmation = arg.slice(arg.indexOf('=') + 1);
  }
}

console.log('[chrome-scraper] Starting automated x.com login...');
if (username) {
  console.log(`[chrome-scraper] Using username: ${username}`);
}
console.log(`[chrome-scraper] Mode: ${headed ? 'Headed (visible Chrome window)' : 'Headless'}`);

try {
  const result = await performLogin({
    username,
    password,
    confirmation,
    headed,
    onProgress: (evt) => {
      if (evt.stage) {
        console.log(`[status] ${evt.stage}${evt.message ? ` - ${evt.message}` : ''}`);
      }
    },
  });

  console.log('[chrome-scraper] Success! Session saved to data/session.json.');
  console.log(`[chrome-scraper] Authenticated as: ${result.username || 'user'}`);
  process.exit(0);
} catch (err) {
  console.error('[chrome-scraper] Login failed:', err.message);
  process.exit(1);
}
