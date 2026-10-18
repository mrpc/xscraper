import fs from 'node:fs';
import path from 'node:path';

const DATA_DIR = process.env.DATA_DIR || path.resolve(process.cwd(), 'data');
const SESSION_FILE = path.join(DATA_DIR, 'session.json');

/**
 * Ensures the data storage directory exists.
 */
function ensureDataDir() {
  if (!fs.existsSync(DATA_DIR)) {
    fs.mkdirSync(DATA_DIR, { recursive: true });
  }
}

/**
 * Loads the active stored session if available.
 *
 * @returns {object|null}
 */
export function loadSession() {
  try {
    if (!fs.existsSync(SESSION_FILE)) {
      return null;
    }
    const raw = fs.readFileSync(SESSION_FILE, 'utf8');
    const data = JSON.parse(raw);
    if (data && data.authToken && data.ct0) {
      return data;
    }
    return null;
  } catch {
    return null;
  }
}

/**
 * Saves session cookies and metadata to persistent disk.
 *
 * @param {object} sessionData
 * @returns {boolean}
 */
export function saveSession(sessionData) {
  try {
    ensureDataDir();
    const payload = {
      authToken: sessionData.authToken,
      ct0: sessionData.ct0,
      username: sessionData.username || null,
      savedAt: new Date().toISOString(),
      cookies: sessionData.cookies || [],
    };
    fs.writeFileSync(SESSION_FILE, JSON.stringify(payload, null, 2), 'utf8');
    return true;
  } catch (err) {
    console.error('[chrome-scraper] Failed to persist session:', err.message);
    return false;
  }
}

/**
 * Removes the stored session file.
 *
 * @returns {boolean}
 */
export function clearSession() {
  try {
    if (fs.existsSync(SESSION_FILE)) {
      fs.unlinkSync(SESSION_FILE);
    }
    return true;
  } catch {
    return false;
  }
}

/**
 * Returns summary info of stored session without full secret tokens.
 *
 * @returns {object}
 */
export function getSessionStatus() {
  const session = loadSession();
  if (!session) {
    return { loggedIn: false };
  }
  return {
    loggedIn: true,
    username: session.username,
    savedAt: session.savedAt,
    hasAuthToken: Boolean(session.authToken),
    hasCt0: Boolean(session.ct0),
  };
}
