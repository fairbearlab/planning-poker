// Dev-only test harness for the vanilla-JS frontend.
//
// app.js is a self-contained IIFE with no exports — it boots itself by reading
// location and wiring the DOM. So we test it the way a browser runs it: inject
// the real app.html body, point location at the URL under test, mock fetch +
// browser APIs, then evaluate the real app.js source. Each load gets a fresh
// IIFE closure, so module-level state (room/token/lastState) never leaks
// between tests.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { vi } from 'vitest';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const appSrc = readFileSync(join(root, 'app.js'), 'utf8');
const appHtml = readFileSync(join(root, 'app.html'), 'utf8');

// Node's real CSPRNG-backed randomUUID — restored on every load so token
// generation behaves like a real browser (and one test can opt out to drive the
// uuidFallback path).
const nativeRandomUUID =
  typeof globalThis.crypto !== 'undefined' ? globalThis.crypto.randomUUID : undefined;

// The markup between <body> and the trailing <script> — exactly what ships,
// minus the script tag (we eval the source ourselves).
const bodyInner = appHtml
  .replace(/[\s\S]*<body>/, '')
  .replace(/<script[\s\S]*<\/body>[\s\S]*/, '');

/**
 * Load app.js into a fresh DOM at the given URL.
 *
 * @param {object} [opts]
 * @param {string} [opts.url]        e.g. '/?room=ABCDEF' (default: bare '/')
 * @param {string|null} [opts.storedName]   value of localStorage 'pp_name'
 * @param {object} [opts.storedTokens]      { CODE: token } preset per-room tokens
 * @param {function} [opts.fetch]   mock fetch; defaults to a vi.fn() you can drive
 * @returns {{ fetch: Function, confirm: Function }}
 */
// jsdom shares one document across every test in a file, but each loadApp() evals
// a fresh app.js that registers its own document-level listeners (notably
// `visibilitychange`, added asynchronously inside startPolling). Left in place,
// stale handlers from earlier loads fire on later visibility changes and trigger
// phantom polls/fetches. We wrap document.addEventListener once to record every
// handler, and tear them all down at the start of each load.
let trackedDocListeners = [];
const nativeDocAdd = document.addEventListener.bind(document);
const nativeDocRemove = document.removeEventListener.bind(document);
document.addEventListener = function (type, handler, options) {
  trackedDocListeners.push({ type, handler, options });
  return nativeDocAdd(type, handler, options);
};
document.removeEventListener = function (type, handler, options) {
  trackedDocListeners = trackedDocListeners.filter(
    (l) => !(l.type === type && l.handler === handler),
  );
  return nativeDocRemove(type, handler, options);
};

export function loadApp(opts = {}) {
  const url = opts.url || '/';

  // Remove any document listeners left behind by a previous load before mounting
  // the next app instance.
  for (const { type, handler, options } of trackedDocListeners) {
    nativeDocRemove(type, handler, options);
  }
  trackedDocListeners = [];

  document.body.innerHTML = bodyInner;
  window.history.replaceState({}, '', url);

  localStorage.clear();
  if (opts.storedName != null) localStorage.setItem('pp_name', opts.storedName);
  for (const [code, tok] of Object.entries(opts.storedTokens || {})) {
    localStorage.setItem('pp_token_' + code, tok);
  }

  const fetchMock = opts.fetch || vi.fn();
  globalThis.fetch = fetchMock;
  window.fetch = fetchMock;

  const confirmMock = opts.confirm || vi.fn(() => true);
  window.confirm = confirmMock;

  // jsdom has no clipboard; provide a resolving stub so copy-link works.
  if (!navigator.clipboard) {
    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText: vi.fn(() => Promise.resolve()) },
      configurable: true,
    });
  }

  // Reset crypto.randomUUID to the real implementation each load. Pass
  // cryptoRandomUuid:false to remove it and exercise app.js's uuidFallback.
  // (globalThis.crypto is a getter-only global in Node — define onto it, never
  // reassign it.)
  if (opts.cryptoRandomUuid === false) {
    Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined, configurable: true });
  } else {
    Object.defineProperty(globalThis.crypto, 'randomUUID', { value: nativeRandomUUID, configurable: true });
  }

  // Indirect eval runs the IIFE in global scope so its bare `document`/`fetch`
  // references resolve to the jsdom globals. boot() runs synchronously because
  // jsdom's readyState is already 'complete'.
  (0, eval)(appSrc);

  return { fetch: fetchMock, confirm: confirmMock };
}

/** A fetch mock that resolves the next response in order, as the real API would. */
export function ok(data, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(data),
  };
}

/** An error response: res.ok=false so app.js throws with .status/.code set. */
export function err(status, body = {}) {
  return { ok: false, status, json: () => Promise.resolve(body) };
}

/** Build a fetch mock that returns queued responses by call order. */
export function sequence(...responses) {
  let i = 0;
  return vi.fn(() => Promise.resolve(responses[Math.min(i++, responses.length - 1)]));
}

/** Flush pending promise microtasks (fetch chains) without advancing timers. */
export async function flush(times = 6) {
  for (let i = 0; i < times; i++) await Promise.resolve();
}

/** A representative full room-state payload; override fields per test. */
export function state(overrides = {}) {
  return Object.assign(
    {
      version: 1,
      participant_id: 'p1',
      room: { name: 'Team Bear', voting_options: ['1', '2', '3', '?', '☕'] },
      round: { id: 'r1', state: 'voting', topic: null },
      participants: [
        { participant_id: 'p1', name: 'Adam', online: true, has_voted: false, value: null },
      ],
      you: { value: null },
      results: null,
    },
    overrides,
  );
}
