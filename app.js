/* Blind Planning Poker — frontend (PLAN §7, Plan B).
   Vanilla JS, no build, no deps. Drives the Section 5 API contract.

   Identity (PLAN §12): a global `pp_name` and a per-room `pp_token_<CODE>`
   live in localStorage. The token is a crypto.randomUUID(); the server treats
   it as identity only, never authority. */

'use strict';

(function () {
  // ───────────────────────── Constants ──────────────────────────────────
  var POLL_MS = 1500;          // §12 poll cadence
  var RECONCILE_EVERY = 7;     // ≈ every 10s, drop `since` to refresh presence

  var DECKS = {
    fib:    ['1', '2', '3', '5', '8', '13', '21', '?', '☕'],
    tshirt: ['XS', 'S', 'M', 'L', 'XL', '?', '☕'],
    pow2:   ['1', '2', '4', '8', '16', '?', '☕']
  };

  // ───────────────────────── Session state ──────────────────────────────
  var room = null;       // room code from ?room=
  var token = null;      // per-room client token
  var name = null;       // display name
  var participantId = null;

  var lastVersion = null;     // last seen room.version (the poll `since`)
  var lastState = null;       // last full state payload rendered
  var pollTimer = null;
  var pollTick = 0;
  var editingTopic = false;   // suppress topic re-render while user types
  var inFlightPoll = false;   // never overlap polls

  // ───────────────────────── DOM helpers ────────────────────────────────
  function $(id) { return document.getElementById(id); }

  function show(screenId) {
    ['screen-landing', 'screen-room', 'screen-error'].forEach(function (id) {
      $(id).hidden = (id !== screenId);
    });
  }

  // Single escape helper — all user-supplied text (name, topic, deck values)
  // goes through this before hitting the DOM (PLAN §7, §9). Escapes the five
  // HTML-significant chars including both quote styles, so it's safe in element
  // content AND attribute context (deck values are interpolated into a
  // data-value="…" attribute in renderHand — quotes must be escaped or an
  // option like `5" onfocus=…` would break out and inject a handler).
  function esc(s) {
    return (s == null ? '' : String(s))
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  var toastTimer = null;
  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, 4000);
  }

  // ───────────────────────── API client ─────────────────────────────────
  // GET endpoints carry params in the query string; POST carry them in a JSON
  // body (the CSRF guard requires application/json — PLAN §5, §9).
  function api(endpoint, opts) {
    opts = opts || {};
    var method = opts.method || 'GET';
    // Directory-relative ("./?api=") so calls hit the front controller even when
    // the page was opened as /app.html directly — a bare "?api=" would post to
    // /app.html (a static file) and never reach index.php's router.
    var url = './?api=' + encodeURIComponent(endpoint);
    var init = { method: method, headers: {}, cache: 'no-store' };

    if (method === 'GET') {
      var q = opts.query || {};
      Object.keys(q).forEach(function (k) {
        if (q[k] != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(q[k]);
      });
    } else {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body || {});
    }

    return fetch(url, init).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error(data.error || ('HTTP ' + res.status));
          err.code = data.code;
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  // ───────────────────────── localStorage identity ──────────────────────
  function tokenKey(code) { return 'pp_token_' + code; }

  function isUuid(s) {
    return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s || '');
  }

  function loadOrMakeToken(code) {
    var k = tokenKey(code);
    var t = null;
    try { t = localStorage.getItem(k); } catch (e) {}
    // Regenerate if missing OR corrupt — a stored value that isn't UUID-shaped
    // (truncated write, manual tampering) would be rejected by the server and
    // leave the user stuck on an unusable room shell.
    if (!isUuid(t)) {
      t = (typeof crypto !== 'undefined' && crypto.randomUUID)
        ? crypto.randomUUID()
        : uuidFallback();
      try { localStorage.setItem(k, t); } catch (e) {}
    }
    return t;
  }

  function uuidFallback() {
    // RFC4122-shaped fallback for the rare browser without crypto.randomUUID.
    // Prefer the CSPRNG; only fall back to Math.random if crypto is absent
    // entirely (non-secure context) so token generation never throws.
    var rand = (typeof crypto !== 'undefined' && crypto.getRandomValues)
      ? function () { return crypto.getRandomValues(new Uint8Array(1))[0] & 15; }
      : function () { return (Math.random() * 16) | 0; };
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = rand();
      var v = c === 'x' ? r : ((r & 0x3) | 0x8);
      return v.toString(16);
    });
  }

  function loadName() {
    try { return localStorage.getItem('pp_name'); } catch (e) { return null; }
  }
  function saveName(n) {
    name = n;
    try { localStorage.setItem('pp_name', n); } catch (e) {}
  }

  // ───────────────────────── Landing / create ───────────────────────────
  function initLanding() {
    show('screen-landing');
    var form = $('create-form');
    var custom = $('custom-deck');

    form.addEventListener('change', function (e) {
      if (e.target.name === 'deck') {
        var isCustom = e.target.value === 'custom';
        custom.hidden = !isCustom;
        if (isCustom) custom.focus();
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var roomName = $('room-name').value.trim();
      if (!roomName) return;

      var pick = form.querySelector('input[name="deck"]:checked').value;
      var options;
      if (pick === 'custom') {
        options = custom.value.split(',').map(function (s) { return s.trim(); })
          .filter(function (s) { return s.length > 0; });
        if (options.length === 0) { toast('Add at least one card to your custom deck.'); return; }
      } else {
        options = DECKS[pick];
      }

      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      api('create_room', { method: 'POST', body: { name: roomName, voting_options: options } })
        .then(function (res) {
          // Redirect into the room; the room flow picks up name/token.
          // Directory-relative so we land on the front controller, not /app.html.
          location.href = './?room=' + encodeURIComponent(res.code);
        })
        .catch(function (err) {
          btn.disabled = false;
          toast(err.message || 'Could not create the room.');
        });
    });
  }

  // ───────────────────────── Name modal ─────────────────────────────────
  function promptName() {
    var modal = $('name-modal');
    modal.hidden = false;
    var input = $('your-name');
    input.value = name || '';
    input.focus();

    $('name-form').onsubmit = function (e) {
      e.preventDefault();
      var n = input.value.trim();
      if (!n) return;
      saveName(n);
      modal.hidden = true;
      doJoin();
    };
  }

  // ───────────────────────── Join + enter room ──────────────────────────
  function doJoin() {
    api('join', { method: 'POST', body: { code: room, name: name, client_token: token } })
      .then(function (state) {
        participantId = state.participant_id;
        show('screen-room');
        applyState(state);
        startPolling();
      })
      .catch(function (err) {
        if (err.status === 404) {
          fatal('Room not found', 'This room link is invalid or has expired.');
        } else {
          toast(err.message || 'Could not join the room.');
        }
      });
  }

  function fatal(title, msg) {
    stopPolling();
    $('error-title').textContent = title;
    $('error-message').textContent = msg;
    show('screen-error');
  }

  // ───────────────────────── Polling loop (§12) ─────────────────────────
  function startPolling() {
    stopPolling();
    document.addEventListener('visibilitychange', onVisibility);
    pollTimer = setInterval(poll, POLL_MS);
  }
  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    document.removeEventListener('visibilitychange', onVisibility);
  }
  function onVisibility() {
    if (document.hidden) {
      if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    } else if (!pollTimer) {
      poll();                       // refresh immediately on return
      pollTimer = setInterval(poll, POLL_MS);
    }
  }

  function poll() {
    if (inFlightPoll || document.hidden) return;
    inFlightPoll = true;

    // Drop `since` every ~10s so presence dots reconcile (heartbeats don't
    // bump version, so a long-quiet room would otherwise freeze its dots).
    pollTick++;
    var sendSince = lastVersion != null && (pollTick % RECONCILE_EVERY !== 0);

    var query = { code: room, token: token };
    if (sendSince) query.since = lastVersion;

    api('state', { query: query })
      .then(function (data) {
        if (data.unchanged) return;   // §8b short-circuit: skip re-render
        applyState(data);
      })
      .catch(function (err) {
        if (err.status === 404) {
          fatal('Room not found', 'This room is no longer available.');
        } else if (err.status === 403) {
          // Our token isn't a participant (e.g. room was purged & recreated).
          fatal('Disconnected', 'You are no longer in this room. Rejoin to continue.');
        }
        // Transient network errors: stay quiet, the next tick retries.
      })
      .then(function () { inFlightPoll = false; });
  }

  // ───────────────────────── Render full state ──────────────────────────
  function applyState(state) {
    lastState = state;
    lastVersion = state.version;

    renderHeader(state);
    renderParticipants(state);
    renderHand(state);
    renderControls(state);
    renderResults(state);
  }

  function renderHeader(state) {
    $('room-name-display').textContent = state.room.name;
    if (!editingTopic) {
      var topic = state.round && state.round.topic;
      var el = $('topic-text');
      if (topic) {
        el.textContent = topic;
        el.classList.remove('muted');
      } else {
        el.textContent = 'what are we voting on?';
        el.classList.add('muted');
      }
    }
  }

  function renderParticipants(state) {
    var revealed = state.round && state.round.state === 'revealed';
    var ul = $('participants');
    ul.innerHTML = state.participants.map(function (p) {
      var isYou = p.participant_id === participantId;
      var stateCell;
      if (revealed) {
        stateCell = p.has_voted
          ? '<span class="pvalue">' + esc(p.value) + '</span>'
          : '<span class="pstate pending">—</span>';
      } else {
        stateCell = p.has_voted
          ? '<span class="pstate check">✓</span>'
          : '<span class="pstate pending">…</span>';
      }
      var presence = p.online ? 'Online' : 'Offline';
      return '<li class="participant">'
        + '<span class="dot ' + (p.online ? 'online' : '') + '" title="' + presence
        + '" role="img" aria-label="' + presence + '"></span>'
        + '<span class="pname' + (isYou ? ' you' : '') + '">' + esc(p.name)
        + (isYou ? ' (you)' : '') + '</span>'
        + stateCell + '</li>';
    }).join('');

    // Vote tally in the panel title.
    var voted = state.participants.filter(function (p) { return p.has_voted; }).length;
    $('vote-tally').textContent = state.participants.length
      ? '· ' + voted + '/' + state.participants.length + ' voted'
      : '';
  }

  function renderHand(state) {
    var hand = $('hand');
    var revealed = state.round && state.round.state === 'revealed';
    var mine = state.you ? state.you.value : null;
    var options = state.room.voting_options || [];

    // Rebuild only when the deck or selection/lock changes — keeps taps snappy.
    var sig = options.join('') + '|' + mine + '|' + revealed + '|'
      + (state.round ? state.round.id : 'none');
    if (hand.dataset.sig === sig) return;
    hand.dataset.sig = sig;

    hand.innerHTML = options.map(function (opt) {
      var selected = opt === mine ? ' selected' : '';
      return '<button type="button" class="card-btn' + selected + '"'
        + (revealed ? ' disabled' : '')
        + ' data-value="' + esc(opt) + '">' + esc(opt) + '</button>';
    }).join('');
  }

  function renderControls(state) {
    var revealBtn = $('reveal-btn');
    var round = state.round;
    var revealed = round && round.state === 'revealed';

    var voted = state.participants.filter(function (p) { return p.has_voted; }).length;
    var onlineUnvoted = state.participants.filter(function (p) {
      return p.online && !p.has_voted;
    }).length;
    var allVoted = state.participants.length > 0 && onlineUnvoted === 0;

    // Reveal enabled once ≥1 vote and the round is still open.
    revealBtn.disabled = revealed || voted === 0;
    if (revealed) {
      revealBtn.textContent = 'Revealed';
      revealBtn.classList.remove('ready');
    } else if (allVoted && voted > 0) {
      revealBtn.textContent = "Everyone's in — reveal?";   // §8c hint
      revealBtn.classList.add('ready');
    } else {
      revealBtn.textContent = 'Reveal votes';
      revealBtn.classList.remove('ready');
    }
  }

  function renderResults(state) {
    var panel = $('results-panel');
    var r = state.results;
    if (!r) { panel.hidden = true; return; }
    panel.hidden = false;

    // Lead with consensus/leading + distribution; average is a secondary,
    // smaller figure so it doesn't anchor the estimate (PLAN §7).
    var headline, headlineClass = '';
    if (r.consensus) {
      headline = 'Consensus: ' + esc(r.leading);
      headlineClass = ' is-consensus';
    } else if (r.leading != null) {
      headline = 'Leading: ' + esc(r.leading);
    } else {
      headline = 'No numeric votes';
    }

    var max = r.distribution.reduce(function (m, d) { return Math.max(m, d.count); }, 0);
    var bars = r.distribution.map(function (d) {
      var pct = max ? Math.round((d.count / max) * 100) : 0;
      var isLeading = !r.consensus && d.value === r.leading;
      return '<div class="dist-row">'
        + '<span class="dist-value">' + esc(d.value) + '</span>'
        + '<span class="dist-bar-track"><span class="dist-bar' + (isLeading ? ' leading' : '')
        + '" style="width:' + pct + '%"></span></span>'
        + '<span class="dist-count">' + esc(d.count) + '</span>'
        + '</div>';
    }).join('');

    // Server-computed numerics are escaped too (defense-in-depth): the frontend
    // treats the response as a trust boundary, so nothing reaches innerHTML raw.
    var secondary = [];
    secondary.push('Average: ' + (r.average == null ? 'N/A' : esc(r.average)));
    if (r.range) secondary.push('Range: ' + esc(r.range.min) + '–' + esc(r.range.max));
    if (r.unsure_count > 0) {
      secondary.push(esc(r.unsure_count) + (r.unsure_count === 1 ? ' person' : ' people') + ' unsure');
    }
    var wide = r.wide_spread ? ' <span class="warn">· wide spread, worth discussing</span>' : '';

    $('results').innerHTML =
      '<div class="results-headline' + headlineClass + '">' + headline + '</div>'
      + '<div class="results-secondary">' + secondary.join(' &nbsp;·&nbsp; ') + wide + '</div>'
      + '<div class="dist">' + bars + '</div>';
  }

  // ───────────────────────── Actions ────────────────────────────────────
  function castVote(value) {
    var round = lastState && lastState.round;
    if (!round || round.state !== 'voting') return;

    // Optimistic: highlight immediately, let the next poll confirm.
    Array.prototype.forEach.call($('hand').children, function (b) {
      b.classList.toggle('selected', b.dataset.value === value);
    });
    if (lastState.you) lastState.you.value = value;

    api('vote', { method: 'POST', body: { token: token, round_id: round.id, value: value } })
      .then(function () { lastVersion = null; poll(); })   // force a fresh render
      .catch(function (err) {
        // On failure, re-sync from the server: the forced poll corrects the
        // optimistic highlight back to the real (un)voted state.
        toast(err.message || 'Vote failed.');
        lastVersion = null; poll();
      });
  }

  function doReveal() {
    var round = lastState && lastState.round;
    if (!round) return;

    var onlineUnvoted = lastState.participants.filter(function (p) {
      return p.online && !p.has_voted;
    }).length;
    if (onlineUnvoted > 0 &&
        !confirm('Reveal now? Not everyone has voted.')) {
      return;
    }

    api('reveal', { method: 'POST', body: { token: token, round_id: round.id } })
      .then(function () { lastVersion = null; poll(); })
      .catch(function (err) { toast(err.message || 'Reveal failed.'); });
  }

  function doNewRound() {
    api('new_round', { method: 'POST', body: { token: token, code: room } })
      .then(function () { lastVersion = null; closeRecap(); poll(); })
      .catch(function (err) { toast(err.message || 'Could not start a new round.'); });
  }

  function saveTopic(value) {
    var round = lastState && lastState.round;
    if (!round) return;
    api('set_topic', { method: 'POST', body: { token: token, round_id: round.id, topic: value } })
      .then(function () { lastVersion = null; poll(); })
      .catch(function (err) { toast(err.message || 'Could not set the topic.'); });
  }

  // ───────────────────────── Recap (§8a) ────────────────────────────────
  function openRecap() {
    var box = $('recap');
    var toggle = $('recap-toggle');
    box.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    toggle.textContent = '▾ Session recap';
    box.innerHTML = '<p class="recap-empty">Loading…</p>';

    api('recap', { query: { code: room, token: token } })
      .then(function (data) { renderRecap(data.rounds || []); })
      .catch(function (err) { box.innerHTML = '<p class="recap-empty">' + esc(err.message) + '</p>'; });
  }
  function closeRecap() {
    $('recap').hidden = true;
    var toggle = $('recap-toggle');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.textContent = '▸ Session recap';
  }

  function renderRecap(rounds) {
    var box = $('recap');
    if (rounds.length === 0) {
      box.innerHTML = '<p class="recap-empty">No revealed rounds yet.</p>';
      return;
    }
    box.innerHTML = rounds.map(function (r, i) {
      var lead = r.consensus ? 'Consensus: ' + esc(r.leading)
        : (r.leading != null ? 'Leading: ' + esc(r.leading) : 'No numeric votes');
      var dist = r.distribution.map(function (d) {
        return esc(d.value) + '×' + d.count;
      }).join(', ');
      return '<div class="recap-round">'
        + '<div class="recap-topic">' + (r.topic ? esc(r.topic) : 'Round ' + (i + 1)) + '</div>'
        + '<div class="recap-meta">' + lead
        + ' &nbsp;·&nbsp; avg ' + (r.average == null ? 'N/A' : esc(r.average))
        + ' &nbsp;·&nbsp; ' + dist + '</div>'
        + '</div>';
    }).join('');
  }

  // ───────────────────────── Room wiring ────────────────────────────────
  function initRoom() {
    name = loadName();
    token = loadOrMakeToken(room);

    // Card taps (delegated).
    $('hand').addEventListener('click', function (e) {
      var btn = e.target.closest('.card-btn');
      if (btn && !btn.disabled) castVote(btn.dataset.value);
    });

    $('reveal-btn').addEventListener('click', doReveal);
    $('new-round-btn').addEventListener('click', doNewRound);

    // Copy link.
    $('copy-link').addEventListener('click', function () {
      // Link to the directory (front controller), not whatever file we're on,
      // so a link copied from /app.html still opens cleanly at /?room=CODE.
      var dir = location.pathname.replace(/[^/]*$/, '');
      var url = location.origin + dir + '?room=' + encodeURIComponent(room);
      var done = function () { toast('Link copied!'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link:', url); });
      } else {
        window.prompt('Copy this link:', url);
      }
    });

    // Inline topic edit.
    var topicEdit = $('topic-edit'), topicForm = $('topic-form'), topicInput = $('topic-input');
    topicEdit.addEventListener('click', function () {
      editingTopic = true;
      topicInput.value = (lastState && lastState.round && lastState.round.topic) || '';
      topicEdit.hidden = true;
      topicForm.hidden = false;
      topicInput.focus();
    });
    function endTopicEdit() {
      editingTopic = false;
      topicForm.hidden = true;
      topicEdit.hidden = false;
      if (lastState) renderHeader(lastState);
    }
    topicForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = topicInput.value.trim();
      endTopicEdit();
      saveTopic(v);
    });
    $('topic-cancel').addEventListener('click', endTopicEdit);

    // Recap toggle.
    $('recap-toggle').addEventListener('click', function () {
      if ($('recap').hidden) openRecap(); else closeRecap();
    });

    // If we have a stored name, join straight away; else prompt.
    if (name) { show('screen-room'); doJoin(); }
    else { promptName(); }
  }

  // ───────────────────────── Boot ───────────────────────────────────────
  function boot() {
    var params = new URLSearchParams(location.search);
    room = params.get('room');
    if (room) { initRoom(); }
    else { initLanding(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
