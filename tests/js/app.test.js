// Integration tests for the frontend (app.js), run in jsdom against the real
// app.html DOM with a mocked fetch. We drive the app the way a user would —
// DOM events — and assert on rendered DOM + the API calls it makes.

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { loadApp, ok, err, sequence, flush, state } from './harness.js';

// A UUID-shaped token — the real backend only accepts UUIDs, and loadOrMakeToken
// now regenerates anything that isn't UUID-shaped, so fixtures must look real.
const TOK = '11111111-1111-4111-8111-111111111111';

// Pull the JSON body out of a POST fetch call: fetch('?api=x', { body }).
function bodyOf(fetchMock, callIndex) {
  return JSON.parse(fetchMock.mock.calls[callIndex][1].body);
}
function apiOf(fetchMock, callIndex) {
  return decodeURIComponent(fetchMock.mock.calls[callIndex][0].split('=')[1].split('&')[0]);
}

beforeEach(() => {
  // Fake timers keep the 1.5s polling interval from running loose between tests.
  vi.useFakeTimers();
});
afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
});

describe('landing / create room', () => {
  it('shows the landing screen when there is no ?room', () => {
    loadApp({ url: '/' });
    expect(document.getElementById('screen-landing').hidden).toBe(false);
    expect(document.getElementById('screen-room').hidden).toBe(true);
  });

  it('reveals the custom-deck input only when "custom" is picked', () => {
    loadApp({ url: '/' });
    const custom = document.getElementById('custom-deck');
    expect(custom.hidden).toBe(true);

    const radio = document.querySelector('input[name="deck"][value="custom"]');
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    expect(custom.hidden).toBe(false);
  });

  it('does not call the API when the session name is blank', () => {
    const { fetch } = loadApp({ url: '/' });
    document.getElementById('room-name').value = '   ';
    document.getElementById('create-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    expect(fetch).not.toHaveBeenCalled();
  });

  it('posts create_room with the chosen preset deck', () => {
    // Never-resolving fetch: we assert the request, not the post-success redirect
    // (location.search assignment is unsupported in jsdom).
    const fetch = vi.fn(() => new Promise(() => {}));
    const h = loadApp({ url: '/', fetch });
    document.getElementById('room-name').value = 'Refinement';
    document.getElementById('create-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(apiOf(h.fetch, 0)).toBe('create_room');
    const body = bodyOf(h.fetch, 0);
    expect(body.name).toBe('Refinement');
    expect(body.voting_options).toEqual(['1', '2', '3', '5', '8', '13', '21', '?', '☕']);
  });

  it('splits, trims and filters a custom deck', () => {
    const fetch = vi.fn(() => new Promise(() => {}));
    const h = loadApp({ url: '/', fetch });
    const radio = document.querySelector('input[name="deck"][value="custom"]');
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('room-name').value = 'Custom sess';
    document.getElementById('custom-deck').value = ' 1 , 2 ,, 3 , ';
    document.getElementById('create-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(bodyOf(h.fetch, 0).voting_options).toEqual(['1', '2', '3']);
  });

  it('rejects an empty custom deck with a toast and no API call', () => {
    const { fetch } = loadApp({ url: '/' });
    const radio = document.querySelector('input[name="deck"][value="custom"]');
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    document.getElementById('room-name').value = 'Custom sess';
    document.getElementById('custom-deck').value = '  , ,  ';
    document.getElementById('create-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    expect(fetch).not.toHaveBeenCalled();
    expect(document.getElementById('toast').hidden).toBe(false);
  });
});

describe('join + identity', () => {
  it('joins immediately when a name is already stored, sending code/name/token', async () => {
    const fetch = sequence(ok(state()));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    expect(apiOf(h.fetch, 0)).toBe('join');
    expect(bodyOf(h.fetch, 0)).toMatchObject({ code: 'ABCDEF', name: 'Adam', client_token: TOK });
    expect(document.getElementById('screen-room').hidden).toBe(false);
    expect(document.getElementById('room-name-display').textContent).toBe('Team Bear');
  });

  it('prompts for a name when none is stored, then saves it and joins', async () => {
    const fetch = sequence(ok(state()));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: null, fetch });

    const modal = document.getElementById('name-modal');
    expect(modal.hidden).toBe(false);
    document.getElementById('your-name').value = 'Boulware';
    document.getElementById('name-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush();

    expect(localStorage.getItem('pp_name')).toBe('Boulware');
    expect(bodyOf(h.fetch, 0).name).toBe('Boulware');
    expect(modal.hidden).toBe(true);
  });

  it('generates and persists a per-room token, reusing it next load', async () => {
    const fetch = sequence(ok(state()));
    loadApp({ url: '/?room=XYZ123', storedName: 'Adam', fetch });
    await flush();
    const tok = localStorage.getItem('pp_token_XYZ123');
    expect(tok).toBeTruthy();
    expect(tok).toMatch(/[0-9a-f-]{36}/i);
  });

  it('shows a fatal "room not found" screen on a 404 join', async () => {
    const fetch = sequence(err(404, { error: 'no room' }));
    loadApp({ url: '/?room=GONE12', storedName: 'Adam', fetch });
    await flush();
    expect(document.getElementById('screen-error').hidden).toBe(false);
    expect(document.getElementById('error-title').textContent).toBe('Room not found');
  });
});

describe('rendering room state', () => {
  async function enterRoom(roomState) {
    const fetch = sequence(ok(roomState));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();
    return h;
  }

  it('escapes participant names (XSS safety)', async () => {
    await enterRoom(state({
      participants: [{ participant_id: 'p2', name: '<img src=x onerror=alert(1)>', online: true, has_voted: false, value: null }],
    }));
    const html = document.getElementById('participants').innerHTML;
    expect(html).not.toContain('<img src=x');
    expect(html).toContain('&lt;img');
  });

  it('does not allow a deck value to break out of the data-value attribute (XSS)', async () => {
    // A quote in a custom deck option must not escape the attribute and inject
    // an event handler. esc() escapes quotes, so the payload stays inert text.
    await enterRoom(state({
      room: { name: 'Team Bear', voting_options: ['5" autofocus onfocus="globalThis.__xss=1'] },
    }));
    const card = document.querySelector('#hand .card-btn');
    // The whole payload is preserved verbatim as the attribute value …
    expect(card.getAttribute('data-value')).toBe('5" autofocus onfocus="globalThis.__xss=1');
    // … and no injected handler/attribute leaked out of it.
    expect(card.hasAttribute('onfocus')).toBe(false);
    expect(card.hasAttribute('autofocus')).toBe(false);
    expect(globalThis.__xss).toBeUndefined();
  });

  it('marks "you", shows the online dot and a voted check', async () => {
    await enterRoom(state({
      participants: [
        { participant_id: 'p1', name: 'Adam', online: true, has_voted: true, value: null },
        { participant_id: 'p2', name: 'Sam', online: false, has_voted: false, value: null },
      ],
    }));
    const ul = document.getElementById('participants');
    expect(ul.querySelector('.pname.you').textContent).toContain('(you)');
    expect(ul.querySelectorAll('.dot.online').length).toBe(1);
    expect(ul.querySelector('.pstate.check').textContent).toBe('✓');
    expect(document.getElementById('vote-tally').textContent).toBe('· 1/2 voted');
  });

  it('reveals values instead of checks once the round is revealed', async () => {
    await enterRoom(state({
      round: { id: 'r1', state: 'revealed', topic: null },
      participants: [{ participant_id: 'p1', name: 'Adam', online: true, has_voted: true, value: '8' }],
    }));
    expect(document.querySelector('#participants .pvalue').textContent).toBe('8');
  });

  it('builds the hand from voting_options and disables cards when revealed', async () => {
    await enterRoom(state({
      round: { id: 'r1', state: 'revealed', topic: null },
      room: { name: 'Team Bear', voting_options: ['1', '2', '3'] },
      you: { value: '2' },
      participants: [{ participant_id: 'p1', name: 'Adam', online: true, has_voted: true, value: '2' }],
    }));
    const cards = document.querySelectorAll('#hand .card-btn');
    expect(cards.length).toBe(3);
    expect([...cards].every((c) => c.disabled)).toBe(true);
    expect(document.querySelector('#hand .card-btn.selected').dataset.value).toBe('2');
  });

  it('controls: reveal disabled with no votes, hinted when everyone has voted', async () => {
    await enterRoom(state({
      participants: [{ participant_id: 'p1', name: 'Adam', online: true, has_voted: false, value: null }],
    }));
    expect(document.getElementById('reveal-btn').disabled).toBe(true);

    await enterRoom(state({
      participants: [{ participant_id: 'p1', name: 'Adam', online: true, has_voted: true, value: '5' }],
    }));
    const btn = document.getElementById('reveal-btn');
    expect(btn.disabled).toBe(false);
    expect(btn.textContent).toContain("Everyone's in");
  });

  it('renders results: consensus headline, distribution bars and secondary stats', async () => {
    await enterRoom(state({
      round: { id: 'r1', state: 'revealed', topic: null },
      results: {
        consensus: true,
        leading: '5',
        average: 5,
        range: { min: 5, max: 5 },
        unsure_count: 1,
        wide_spread: false,
        distribution: [{ value: '5', count: 3 }, { value: '?', count: 1 }],
      },
    }));
    expect(document.getElementById('results-panel').hidden).toBe(false);
    expect(document.querySelector('.results-headline').textContent).toContain('Consensus: 5');
    const bars = document.querySelectorAll('.dist-bar');
    expect(bars[0].style.width).toBe('100%');   // max count
    expect(bars[1].style.width).toBe('33%');    // 1/3 rounded
    expect(document.querySelector('.results-secondary').textContent).toContain('1 person unsure');
  });

  it('hides the results panel when there are no results', async () => {
    await enterRoom(state());
    expect(document.getElementById('results-panel').hidden).toBe(true);
  });
});

describe('actions', () => {
  it('casting a vote highlights optimistically and posts the value', async () => {
    const fetch = sequence(ok(state()), ok({}), ok(state({ version: 2, you: { value: '3' } })));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    const card = [...document.querySelectorAll('#hand .card-btn')].find((c) => c.dataset.value === '3');
    card.click();
    // Optimistic highlight is synchronous, before the network resolves.
    expect(card.classList.contains('selected')).toBe(true);
    await flush();

    expect(apiOf(h.fetch, 1)).toBe('vote');
    expect(bodyOf(h.fetch, 1)).toMatchObject({ token: TOK, round_id: 'r1', value: '3' });
  });

  it('reveal asks for confirmation when online voters are still out', async () => {
    const confirm = vi.fn(() => false);
    const fetch = sequence(ok(state({
      participants: [
        { participant_id: 'p1', name: 'Adam', online: true, has_voted: true, value: '5' },
        { participant_id: 'p2', name: 'Sam', online: true, has_voted: false, value: null },
      ],
    })));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch, confirm });
    await flush();

    document.getElementById('reveal-btn').click();
    expect(confirm).toHaveBeenCalled();
    // Declined → no reveal call (only the initial join fetch happened).
    expect(h.fetch.mock.calls.length).toBe(1);
  });

  it('new round posts new_round with the room code', async () => {
    const fetch = sequence(ok(state()), ok({}), ok(state({ version: 2 })));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();
    document.getElementById('new-round-btn').click();
    await flush();
    expect(apiOf(h.fetch, 1)).toBe('new_round');
    expect(bodyOf(h.fetch, 1)).toMatchObject({ token: TOK, code: 'ABCDEF' });
  });

  it('inline topic edit posts set_topic', async () => {
    const fetch = sequence(ok(state()), ok({}), ok(state({ version: 2, round: { id: 'r1', state: 'voting', topic: 'Login flow' } })));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    document.getElementById('topic-edit').click();
    document.getElementById('topic-input').value = 'Login flow';
    document.getElementById('topic-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush();

    expect(apiOf(h.fetch, 1)).toBe('set_topic');
    expect(bodyOf(h.fetch, 1)).toMatchObject({ token: TOK, round_id: 'r1', topic: 'Login flow' });
  });
});

describe('polling', () => {
  it('skips re-render on an {unchanged} poll response', async () => {
    const fetch = sequence(
      ok(state()),                 // join
      ok({ unchanged: true }),     // first poll
    );
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();
    const before = document.getElementById('participants').innerHTML;

    await vi.advanceTimersByTimeAsync(1500);
    await flush();
    expect(document.getElementById('participants').innerHTML).toBe(before);
  });

  it('drops `since` every 7th poll so presence reconciles', async () => {
    // Always return unchanged so we only inspect the query strings sent.
    const fetch = vi.fn((url) => {
      if (url.indexOf('api=join') !== -1) return Promise.resolve(ok(state()));
      return Promise.resolve(ok({ unchanged: true }));
    });
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    for (let i = 0; i < 7; i++) {
      await vi.advanceTimersByTimeAsync(1500);
      await flush();
    }
    const stateCalls = fetch.mock.calls.map((c) => c[0]).filter((u) => u.indexOf('api=state') !== -1);
    // First 6 carry since=, the 7th (pollTick % 7 === 0) drops it to reconcile.
    expect(stateCalls[0]).toContain('since=');
    expect(stateCalls[6]).not.toContain('since=');
  });

  it('shows a "Disconnected" fatal screen on a 403 poll', async () => {
    const fetch = sequence(ok(state()), err(403, { error: 'not a participant' }));
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    await vi.advanceTimersByTimeAsync(1500);
    await flush();
    expect(document.getElementById('screen-error').hidden).toBe(false);
    expect(document.getElementById('error-title').textContent).toBe('Disconnected');
  });
});

describe('recap', () => {
  it('toggles open, fetches recap and renders revealed rounds', async () => {
    const fetch = sequence(
      ok(state()),
      ok({ rounds: [{ topic: 'Search', consensus: false, leading: '8', average: 7.5, distribution: [{ value: '8', count: 2 }, { value: '5', count: 1 }] }] }),
    );
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    document.getElementById('recap-toggle').click();
    await flush();
    expect(apiOf(h.fetch, 1)).toBe('recap');
    const recap = document.getElementById('recap');
    expect(recap.hidden).toBe(false);
    expect(recap.querySelector('.recap-topic').textContent).toBe('Search');
    expect(recap.querySelector('.recap-meta').textContent).toContain('Leading: 8');
  });

  it('shows an empty-state message when there are no revealed rounds', async () => {
    const fetch = sequence(ok(state()), ok({ rounds: [] }));
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();
    document.getElementById('recap-toggle').click();
    await flush();
    expect(document.querySelector('#recap .recap-empty').textContent).toContain('No revealed rounds');
  });
});

describe('resilience: errors, lifecycle, identity', () => {
  function enterRoomFetch(...after) {
    return sequence(ok(state()), ...after);
  }

  it('re-syncs from the server when a vote fails', async () => {
    const fetch = enterRoomFetch(err(409, { error: 'round closed' }), ok(state({ version: 2 })));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    [...document.querySelectorAll('#hand .card-btn')].find((c) => c.dataset.value === '3').click();
    await flush();

    // vote (call 1) failed → toast shown and a fresh state poll (call 2) issued
    // without `since` (lastVersion was reset) to correct the optimistic highlight.
    expect(document.getElementById('toast').hidden).toBe(false);
    expect(apiOf(h.fetch, 2)).toBe('state');
    expect(h.fetch.mock.calls[2][0]).not.toContain('since=');
  });

  it('surfaces a generic HTTP error message when the body is not JSON', async () => {
    const badRes = { ok: false, status: 500, json: () => Promise.reject(new Error('not json')) };
    const fetch = enterRoomFetch(badRes);
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    document.getElementById('new-round-btn').click();
    await flush();
    expect(document.getElementById('toast').textContent).toBe('HTTP 500');
  });

  it('stays quiet (no fatal screen) on a transient 500 during polling', async () => {
    const fetch = enterRoomFetch(err(500, { error: 'boom' }));
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    await vi.advanceTimersByTimeAsync(1500);
    await flush();
    expect(document.getElementById('screen-error').hidden).toBe(true);
    expect(document.getElementById('screen-room').hidden).toBe(false);
  });

  it('pauses polling while the tab is hidden and resumes on return', async () => {
    const fetch = vi.fn((url) =>
      Promise.resolve(url.indexOf('api=join') !== -1 ? ok(state()) : ok({ unchanged: true })));
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    document.dispatchEvent(new Event('visibilitychange'));
    const hiddenAt = fetch.mock.calls.length;
    await vi.advanceTimersByTimeAsync(3000);
    await flush();
    expect(fetch.mock.calls.length).toBe(hiddenAt);          // no polls while hidden

    Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
    document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    expect(fetch.mock.calls.length).toBeGreaterThan(hiddenAt); // immediate poll on return
    delete document.hidden;
  });

  it('falls back to window.prompt when the clipboard API is unavailable', async () => {
    const fetch = sequence(ok(state()));
    loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null);
    document.getElementById('copy-link').click();
    expect(promptSpy).toHaveBeenCalled();
    expect(promptSpy.mock.calls[0][1]).toContain('?room=ABCDEF');
  });

  it('regenerates a corrupt (non-UUID) stored token instead of sending it', async () => {
    const fetch = sequence(ok(state()));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: 'not-a-uuid' }, fetch });
    await flush();
    const sent = bodyOf(h.fetch, 0).client_token;
    expect(sent).not.toBe('not-a-uuid');
    expect(sent).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i);
    expect(localStorage.getItem('pp_token_ABCDEF')).toBe(sent);
  });

  it('generates a valid token via uuidFallback when crypto.randomUUID is missing', async () => {
    const fetch = sequence(ok(state()));
    loadApp({ url: '/?room=ZZZ999', storedName: 'Adam', fetch, cryptoRandomUuid: false });
    await flush();
    const tok = localStorage.getItem('pp_token_ZZZ999');
    // RFC4122 v4 shape: version nibble 4, variant nibble 8/9/a/b.
    expect(tok).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  });

  it('cancels an inline topic edit without calling set_topic', async () => {
    const fetch = sequence(ok(state()));
    const h = loadApp({ url: '/?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();

    document.getElementById('topic-edit').click();
    expect(document.getElementById('topic-form').hidden).toBe(false);
    document.getElementById('topic-cancel').click();
    expect(document.getElementById('topic-form').hidden).toBe(true);
    expect(document.getElementById('topic-edit').hidden).toBe(false);
    expect(h.fetch.mock.calls.length).toBe(1); // only the join call
  });

  it('uses a directory-relative API URL so /app.html entry still reaches the router', async () => {
    const fetch = sequence(ok(state()));
    const h = loadApp({ url: '/app.html?room=ABCDEF', storedName: 'Adam', storedTokens: { ABCDEF: TOK }, fetch });
    await flush();
    expect(h.fetch.mock.calls[0][0]).toMatch(/^\.\/\?api=join/);
  });
});
