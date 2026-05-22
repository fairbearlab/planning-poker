# Blind Planning Poker — Official MVP Plan

> Status: approved for build. Sized for a pair, ~1 focused day.
> Derived from `planning-poker-mvp-plan.md` with four locked decisions (Section 0).

---

## 0. Locked decisions

These were confirmed before the build and override anything ambiguous below.

1. **Hosting: firm PHP request-only host, but keep the code portable.** Build to the
   request-scoped PHP + SQLite + short-polling stack. *But* keep clean seams so the
   storage layer and the transport layer could be swapped later (e.g. to Postgres, or
   to a long-lived runtime with SSE) without rewriting the domain logic. See
   Section 3a — this is the one place we spend extra effort up front.
2. **Teams integration: just a URL.** The room link is pasted into the Teams meeting
   invite. No Teams API, no tab/app, no embedding. Nothing in the design needs to
   anticipate a Teams app.
3. **Scope:** Core (Sections 1–7) **+ Session recap** (Section 8a) **+ polling version
   counter** (Section 8b) **+ "everyone has voted" hint** (Section 8c). All three are
   cheap; the all-voted hint was added after the outside-voice review flagged it as
   higher-value-per-minute than recap for facilitation — we kept both.
4. **Durability posture: lean but clean.** Build fast, but get the three costly-to-
   migrate things right: the **data model**, **client-token identity**, and the
   **server-side blind-vote boundary**. Everything else optimizes for speed.

---

## 1. Goal

A lightweight, distributed planning-poker app for Teams-based sprint refinement and
planning. The presenter screen-shares Jira stories, the team blind-votes estimates,
and any participant can reveal the results — which show every estimate plus an
average and a consensus read. No accounts: just a name on first load.

**Usage pattern:** 2 refinement sessions + 1 planning session per team per sprint,
2 teams. Each session is independent. One room per session; the room URL goes in the
Teams meeting invite.

**Hard constraints driving the design:**

- Runs on **request-only PHP hosting** — no daemons, no background processes, no Node/npm.
- **Nothing installed on the server** — deploy is "copy a folder."
- **SQLite** for storage.
- Buildable by a pair in roughly **one day**.

---

## 2. Stack decision

| Layer | Choice | Why |
|---|---|---|
| Backend | Single vanilla **PHP 7.4** front controller | Already on the host, zero install, request-scoped. No framework, no Composer, no build step. |
| Storage | **SQLite** via PDO, WAL mode | One file, no DB server. WAL + `busy_timeout` handles a dozen concurrent voters comfortably. |
| Frontend | One HTML shell + vanilla JS + CSS, **no build** | No npm, no bundler, no CDN dependency — fully self-contained. |
| Live updates | **Short-polling** (~1.5s) | No daemon means SSE/WebSockets would pin a PHP-FPM worker per client. Polling a cheap JSON endpoint is trivial load at this scale and more robust. |

**Why not Go/Node:** a static binary or Node server needs a long-lived process the
host can't run. On this server a single PHP file *is* the lean option. The portability
seams in Section 3a mean that if hosting ever changes, the domain logic moves with us.

**Host prerequisites (2-minute check before building):**

- PHP 7.4 (the production runtime; code must stay 7.4-compatible — no 8.x-only syntax).
- `pdo_sqlite` enabled — `php -m | grep sqlite`. On by default almost everywhere.
- One web-writable directory for the SQLite file.

---

## 3. Architecture & file layout

```
planning-poker/
├─ index.php       # front controller: HTTP routing + request/response only
├─ api.php         # domain logic: one function per endpoint (transport-agnostic)
├─ store.php       # storage layer: ALL SQL lives here, behind named functions
├─ db.php          # PDO bootstrap, schema migration, pragmas
├─ stats.php       # pure functions: consensus / average / distribution
├─ app.html        # single-page UI shell
├─ app.js          # frontend logic (join, poll, vote, reveal, recap)
├─ style.css       # styling
├─ .htaccess       # deny direct access to *.db and data/
└─ data/
   └─ poker.db     # created at runtime; this dir must be writable
```

Deploy = copy the `planning-poker/` folder to the host and make `data/` writable.
That is the whole install.

**Routing** is a tiny hand-rolled switch in `index.php` — no router library:

- `GET /` with no `api` param → output `app.html`.
- `?api=<name>` → parse + validate input, call the matching `api.php` function,
  JSON-encode the result, set the HTTP status. `index.php` does **no business logic**.
- `app.js`, `style.css` → served directly as static files.

`db.php` runs `CREATE TABLE IF NOT EXISTS` on every request (cheap, idempotent) — no
separate migration step. Pragmas on connect:

- `PRAGMA journal_mode = WAL;` — readers don't block the writer.
- `PRAGMA busy_timeout = 5000;` — wait out brief write locks instead of erroring.
- `PRAGMA foreign_keys = ON;` — enables `ON DELETE CASCADE` for cleanup.

### 3a. Portability seams (the "keep portable" investment)

The lock-in we're avoiding is *coupling domain logic to PHP request-handling and to
SQLite-specific SQL*. Three layers, each with one job:

- **Transport (`index.php`)** — knows about HTTP, query params, status codes, JSON
  encoding. Knows nothing about rooms or votes. Swapping to a different runtime or
  adding SSE later means rewriting only this file.
- **Domain (`api.php`, `stats.php`)** — pure-ish functions: take plain PHP
  arrays/scalars, return plain arrays. Enforce all business rules here, including the
  blind-vote boundary. No `$_GET`, no `echo`, no SQL. `stats.php` is fully pure and
  unit-testable.
- **Storage (`store.php`, `db.php`)** — every SQL string lives here behind intention-
  revealing functions (`create_room`, `current_round`, `upsert_vote`,
  `votes_for_round`, …). Domain code calls these by name. Moving to Postgres later
  means touching only this layer.

This is *seams, not abstraction layers* — no interfaces, no repository classes, no
DI. Just three files that don't reach across each other's boundaries. Cheap to write,
and it's what makes every other "what if hosting changes" question a non-event.

**Concurrency (eng-review decision).** `store.php` exposes `with_transaction(fn)` — it
runs `BEGIN IMMEDIATE`, calls `fn`, commits, and rolls back on any exception. The domain
wraps each mutation in one call; the transaction boundary lives in one place, so an
exception can't leak a half-open transaction (the "store opens, handler commits" split
was a leak trap — fixed here). This makes the `room.version` bump atomic: two
near-simultaneous mutations can't lose an increment (a write-write race), and the
round-lifecycle invariants hold under concurrent clicks. The `new_round` dedup runs
*inside* this transaction so concurrent New Round calls serialize — the test must
exercise concurrent calls, not just a sequential double-call.

---

## 4. Data model

**`rooms`**

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | |
| `code` | TEXT UNIQUE | 12-char base32 code used in the URL (~60 bits; raised from 8 after the outside-voice review noted 40 bits is enumerable without rate limiting). |
| `name` | TEXT | Session name, e.g. "Team Bear — Refinement". |
| `voting_options` | TEXT | JSON array of strings, e.g. `["1","2","3","5","8","13","?","☕"]`. |
| `version` | INTEGER | Monotonic counter; bumped on every state-changing mutation. Drives the polling short-circuit (Section 8b). |
| `created_at` | INTEGER | Unix timestamp. |
| `last_activity_at` | INTEGER | Bumped on any mutation; used for stale-room cleanup. |

**`participants`**

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | |
| `room_id` | INTEGER FK | `ON DELETE CASCADE` |
| `name` | TEXT | Display name. |
| `client_token` | TEXT | Random token (`crypto.randomUUID()`), stored in localStorage. Identity across polls and refreshes. `UNIQUE(room_id, client_token)`. |
| `joined_at` | INTEGER | |
| `last_seen_at` | INTEGER | Updated on every poll; drives the online/offline dot. |

**`rounds`**

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | |
| `room_id` | INTEGER FK | `ON DELETE CASCADE` |
| `topic` | TEXT NULL | Story title / "what we're voting on". Optional. |
| `state` | TEXT | `voting` or `revealed`. |
| `created_at` | INTEGER | |
| `revealed_at` | INTEGER NULL | |

A room's **current round** is its most recent `rounds` row. Older rounds stay in the
table — that is what makes the session recap free.

**`votes`**

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | |
| `round_id` | INTEGER FK | `ON DELETE CASCADE` |
| `participant_id` | INTEGER FK | `ON DELETE CASCADE` |
| `value` | TEXT | Chosen option; must be a member of the room's `voting_options`. |
| `updated_at` | INTEGER | |
| | | `UNIQUE(round_id, participant_id)` — one vote per person per round; changes are an upsert. |

**Title scope:** the room has a `name` (set at creation); each round has an optional
`topic`. Since the presenter screen-shares Jira, the team can leave `topic` blank —
but filling it makes the recap useful.

> **Durability note (decision 4):** schema, `client_token` identity, and the
> `UNIQUE` constraints above are the costly-to-migrate parts. They get reviewed before
> the frontend is wired up.

---

## 5. API surface

All endpoints are `index.php?api=<name>`, JSON in / JSON out, correct HTTP status codes.
Each maps to one function in `api.php`.

| Endpoint | Method | Body | Returns |
|---|---|---|---|
| `create_room` | POST | `name`, `voting_options[]` | `{ code }` |
| `join` | POST | `code`, `name`, `client_token` | `{ participant_id }` + full room state. **Idempotent:** find-or-create on `(room_id, client_token)`; a repeated call (refresh, double-tap) returns the same `participant_id`, never errors. |
| `state` | GET | `code`, `token`, `since?` | Full room state (below), or `{ unchanged: true }` if `since == room.version`. Also bumps `last_seen_at`. |
| `vote` | POST | `token`, `round_id`, `value` | `{ ok }` — upserts the vote. Rejected if the round is `revealed`. |
| `reveal` | POST | `token`, `round_id` | `{ ok }` — sets round to `revealed`. Any participant may call it. |
| `new_round` | POST | `token`, `code`, `topic?` | `{ round_id }` — creates a fresh `voting` round. **Idempotent dedup:** if the current round is a `voting` round with zero votes, reuse it instead of creating a duplicate (kills the double-click double-round). Any participant may call it. |
| `set_topic` | POST | `token`, `round_id`, `topic` | `{ ok }` — set/edit the current round's topic. |
| `recap` | GET | `code`, `token` | `{ rounds: [{ round_id, topic, average, consensus, leading, distribution }] }` — read-only history (Section 8a). Intentionally a compact summary: `range`/`wide_spread`/`unsure_count` are omitted here (live `state.results` carries the full set). |

**`state` response shape** (the one the client polls):

```json
{
  "version": 17,
  "room":   { "code": "...", "name": "...", "voting_options": ["1","2",...] },
  "round":  { "id": 42, "topic": "...", "state": "voting" },
  "you":    { "participant_id": 7, "value": "5" },
  "participants": [
    { "participant_id": 7, "name": "Adam", "online": true, "has_voted": true,  "value": null },
    { "participant_id": 8, "name": "Jayme","online": true, "has_voted": false, "value": null }
  ],
  "results": null
}
```

**The blind-voting rule lives in the domain layer (`api.php`), not the client.** While
`round.state == "voting"`, every *other* participant's `value` is `null` in the
payload — only `has_voted` is exposed. The caller sees only their own value under
`you.value`. Other people's vote values are never serialized to the wire until reveal.
This is enforced server-side and verified in the smoke test (devtools network check).

When `round.state == "revealed"`, each participant's real `value` is populated and
`results` is filled in (Section 6).

**Validation via shared guards (eng-review decision, signature corrected after outside
voice).** Several endpoints carry `round_id` but not `code`, so the guard must resolve
the room itself rather than trust a passed `code`. The helpers in `api.php`:

- `resolve_room($req)` → returns the room row, deriving it from `code` when present, else
  from `round_id` (`rounds.room_id`). `404 room_not_found` if neither resolves.
- `require_participant($token, $room)` → returns the participant row or `403`. Confirms
  the token maps to a participant *in that resolved room* — the room is never taken on
  the client's word.
- `assert_current_round($round_id, $room)` → confirms `round_id` is the room's current
  (most-recent) round, else `409`. This is what stops `set_topic`/`vote`/`reveal` from
  mutating a past/revealed round.

Plus per-field: `value` ∈ `voting_options`; length caps on `name`/`topic`;
`client_token` must match a UUID-shaped format (reject empty/garbage). Any *real* state
change bumps `room.version` and `last_activity_at` (see version semantics below).

**Request hardening (outside-voice decision).** Mutating endpoints accept `Content-Type:
application/json` only and reject form-encoded bodies — a cheap CSRF guard (a malicious
page can't forge a simple cross-origin JSON POST without a preflight). No cookies are
used for identity, which removes the classic CSRF vector regardless.

**Error envelope (eng-review decision).** Every non-2xx response returns a consistent body
so the frontend renders errors one way:

```json
{ "error": "Human-readable message", "code": "machine_slug" }
```

Domain codes: `room_not_found` (404), `not_a_participant` (403), `round_not_current`
(409), `invalid_value` (422), `round_revealed` (409 — vote after reveal).

Transport codes (from `index.php`, same envelope): `unknown_endpoint` (404),
`method_not_allowed` (405), `unsupported_media_type` (415 — non-JSON POST),
`invalid_json` (400 — unparseable body), `internal_error` (500 — last-resort catch).

**Version semantics (eng-review decision, ordering clarified after outside voice).**
`room.version` is bumped **only** on real state changes — `vote`, `reveal`, `new_round`,
`set_topic`, and a `join` that actually creates a participant or changes a name (an
idempotent rejoin with the same name does **not** bump version, so reopening the tab
doesn't force everyone to re-render). The `last_seen_at` heartbeat does **not** bump
version (otherwise every client's heartbeat would invalidate every other client's
short-circuit and the optimization would never fire).

`state` ordering: always do the one tiny indexed `last_seen_at` UPDATE (the heartbeat),
then read `room.version`; if `since == version`, return `{unchanged:true}` *without*
assembling participants/votes. So an unchanged poll is one cheap write + one indexed
read — not a pure single SELECT, but still trivial. To keep presence dots fresh, the
client drops `since` every ~10s to force a full reconcile.

---

## 6. Reveal: stats & consensus

Pure functions in `stats.php`, computed when `state` is requested for a revealed round.

- **Cast votes** = participants who voted. Non-voters show "—" and are excluded from math.
- **Numeric votes** = cast votes whose value parses as a number. `?` and `☕` are
  excluded from the average but still shown in the distribution.
- **Average** = mean of numeric votes, rounded to 1 decimal. `N/A` if none.
- **Distribution** = count per distinct value, e.g. `{"5": 3, "8": 2, "?": 1}`.
- **Consensus** = if every cast vote is identical → "Consensus: X". Otherwise the mode
  is the leading estimate, shown alongside the spread. **Tie-break (deterministic):** if
  two values tie for most-frequent, the *lower* deck value wins the `leading` slot
  (estimating conservatively understates risk less than overstating); ties are resolved
  by the value's index in `voting_options`, so behavior is stable and testable.
- **Range** = min–max of numeric votes. A wide spread (low and high more than two deck
  steps apart) is flagged as a discussion prompt.
- **Unsure flag** = if anyone voted `?`, surface it ("1 person unsure").

`results` payload:

```json
{
  "average": 6.2,
  "consensus": false,
  "leading": "5",
  "range": { "min": 3, "max": 13 },
  "distribution": [
    { "value": "3", "count": 1 },
    { "value": "5", "count": 3 },
    { "value": "8", "count": 1 },
    { "value": "?", "count": 1 }
  ],
  "wide_spread": true,
  "unsure_count": 1
}
```

`distribution` is an ordered **list** of `{ value, count }` (deck order), not a
`value: count` object — PHP recasts numeric-string keys to ints, so a 0-based
numeric deck would otherwise serialize as a JSON array and lose its labels.

---

## 7. Frontend UX flow

Single page, no router. States:

1. **Landing (no room).** "Create a room": room name + deck picker. Presets:
   - Modified Fibonacci — `1, 2, 3, 5, 8, 13, 21, ?, ☕`
   - T-shirt — `XS, S, M, L, XL, ?, ☕`
   - Powers of 2 — `1, 2, 4, 8, 16, ?, ☕`
   - Custom — comma-separated input

   On submit → `create_room` → redirect to `?room=CODE`.

2. **Name prompt.** Opening `?room=CODE` with no stored name shows a "Your name" modal.
   On submit: store `pp_name` (global) in localStorage, generate and store
   `pp_token_<CODE>` (per room), call `join`.

3. **Room view.**
   - **Header:** room name; current-round topic shown inline and click-to-edit
     ("what are we voting on?"); a Copy-link button for the room URL.
   - **Participants:** name + online dot + a ✓ once they've voted (during voting), or
     their card value (after reveal).
   - **Your hand:** the deck rendered as card buttons. Tap to vote; chosen card is
     highlighted. Re-tap to change — allowed until reveal.
   - **Controls (visible to everyone):** "Reveal votes" (enabled once ≥1 vote) and
     "New round". **Reveal confirms** ("Reveal now? Not everyone has voted.") when at
     least one online participant hasn't voted yet — guards against an accidental tap
     derailing the round (outside-voice decision).
   - **Results panel (after reveal):** lead with the **Consensus / leading-estimate**
     line and the distribution bar (these drive the discussion); show the **average as a
     secondary, smaller figure** alongside the range. The mean is a supporting signal,
     not the headline, so it doesn't anchor the team's estimate (outside-voice decision).
     Then "New round" to go again.
   - **Recap (Section 8a):** a "Session recap" toggle listing past rounds.

4. **Polling.** `setInterval` ~1.5s calling `state`, passing the last-seen `version`
   as `since`. On `{ unchanged: true }`, skip re-render entirely. Every ~10s (≈ every
   7th poll) drop `since` to force a full fetch so presence dots reconcile. Pause
   polling when the tab is hidden (`visibilitychange`).

**Round lifecycle:** room creation makes the first `voting` round → people tap cards
(upsert votes) → anyone hits Reveal → next poll shows values + stats → anyone hits New
Round (optionally naming the next story) → fresh `voting` round, new vote rows. Old
votes stay attached to old rounds.

**Edge cases handled:** refresh re-identifies via stored token; joining mid-round adds
you to the current round; revealing with non-voters present is allowed (they show "—");
duplicate names are fine (token disambiguates); room-not-found shows a friendly
message; all user-supplied text (`name`, `topic`) is HTML-escaped on render.

---

## 8. Persistence & cleanup

Rooms persist in SQLite, so a room URL stays valid for the whole session (and a bit
beyond — handy for rejoins). Each session is its own room; no cross-session sharing.

**Cleanup:** *opportunistic* (not scheduled — there are no cron/daemons on this host).
On `create_room`, delete rooms whose `last_activity_at` is older than 30 days;
`ON DELETE CASCADE` removes their participants, rounds, and votes. Caveat: if nobody
creates a room for a long stretch, old data simply lingers — it's harmless at this
scale (a few rooms per sprint), but it's best-effort, not a guarantee.

### 8a. Session recap (IN SCOPE)

Because rounds persist within a room, the `recap` endpoint returns each past round's
topic + average + consensus/leading + distribution, computed with the same `stats.php`
functions. The frontend renders it as a read-only list behind a "Session recap"
toggle in the room view. No new tables — pure read over existing `rounds`/`votes`.

### 8b. Polling version counter (IN SCOPE)

`rooms.version` is bumped on real state changes only (see "Version semantics" in
Section 5). The `state` poll sends `since=<version>`; when nothing changed the server
returns `{ unchanged: true }` and the client skips re-render. The client drops `since`
every ~10s to reconcile presence (heartbeats don't bump version). This keeps the 1.5s
poll near-free and avoids redundant DOM work. Cheap to build now and annoying to
retrofit, so it's in.

### 8c. "Everyone has voted" hint (IN SCOPE)

The `state` payload already carries `has_voted` per participant. The client derives
`all_voted` = every *online* participant has voted, and when true highlights the Reveal
button ("Everyone's in — reveal?"). Pure client-side off existing data; no new endpoint,
no server change. Added per the outside-voice review as a high-value facilitation cue —
it's the moment the facilitator is waiting for. Pairs with the reveal-confirm in
Section 7 (the confirm only fires when `all_voted` is false).

---

## 9. Security notes (MVP-appropriate)

- **No auth by design.** The 12-char base32 room code (~60 bits) is the only gate —
  unlisted and not realistically enumerable. Internal trusted-team use, low stakes.
- **"Anyone can reveal / new round"** is intentional, now softened: reveal confirms when
  not everyone has voted (Section 7). Early reveal is recoverable (start a new round).
- **Protect the DB file — `data/` outside the web root is the default** (outside-voice
  decision). `.htaccess` denying `*.db`/`data/` is a *fallback* for hosts that don't
  allow it, not the primary defense — `.htaccess` only works on Apache honoring overrides.
  If the host can't place `data/` outside web root, that's a deploy-time red flag to
  resolve, not paper over.
- **Escape user content.** `name` and `topic` are shown to others — escape on render
  with a single UTF-8-aware helper, cap input lengths server-side.
- **CSRF.** No cookies for identity (token in JSON body), and mutating endpoints accept
  `application/json` only — a forged cross-origin form POST can't reach them.
- **Token validation.** `client_token` must be UUID-shaped; reject empty/garbage. The
  token is identity only, never authority — participant IDs grant nothing on their own.
- **No rate limiting** at this scale; WAL + `busy_timeout` covers concurrency. (Revisit
  only if the app ever leaves the trusted-team setting.)
- **UTF-8 end to end.** Deck values include `☕`: set the PDO connection charset, encode
  JSON with `JSON_UNESCAPED_UNICODE`, escape with the UTF-8 helper, and include a
  non-ASCII value in the stats tests so encoding regressions are caught.

---

## 10. Testing approach (eng-review decision)

**Dev-only PHPUnit.** Tests use PHPUnit installed via Composer, run only in dev — never
deployed. The "copy a folder" deploy copies only the runtime files (Section 3 layout);
`tests/`, `composer.json`, `composer.lock`, and `vendor/` are excluded from the deployed
set and gitignored as appropriate. The shipped artifact still has zero runtime
dependencies — Composer is a dev-time tool only.

What gets tested (target: every code path from the Section 10b coverage map):

- **`stats.php` (pure, highest ROI):** average (mixed numeric + `?`/`☕`; all-non-numeric
  → N/A), consensus (identical vs split), leading/mode (incl. tie-break), range +
  `wide_spread`, distribution, `unsure_count`. Single-voter and empty-votes edges.
- **`api.php` against a temp SQLite file (fresh DB per test):**
  - **Blind mask (CRITICAL, regression-class):** during `voting`, others' `value` is
    `null` in the `state` payload; only `has_voted` exposed. Once green, must stay green.
  - `vote` rejected on a revealed round (returns `{error, code}` + 409).
  - `new_round` dedup: a double call reuses the empty voting round → exactly one round.
  - `join` idempotency: repeated call with same `client_token` → same `participant_id`.
  - version short-circuit: `since == version` → `{ unchanged: true }`.
  - guard helpers: bad token → 403; stale `round_id` → 409.
- **`db.php`:** schema creates, pragmas set (smoke).

---

## 10a. Phased execution plans

The build splits into three independently-executable plans. **Plan A and Plan B can run
in parallel** (different files, no shared modules); **Plan C depends on both.** See
Section 13 for the worktree/parallelization detail.

### Plan A — Backend + tests (`store.php`, `api.php`, `db.php`, `stats.php`, `index.php`, `tests/`)

*~3.5 hr.* The whole server, test-first where cheap.

1. **Setup:** file skeleton; `db.php` schema (incl. `version`), WAL/FK/`busy_timeout`
   pragmas; `data/` placed outside web root (`.htaccess` deny as fallback). **Host
   probe (do this before writing code):** confirm `pdo_sqlite`, a writable dir, *and*
   that WAL actually sticks on this host's filesystem — open the DB, set
   `journal_mode=WAL`, read it back (some shared/network filesystems silently fall back),
   and fire two concurrent writes to confirm `busy_timeout` absorbs the lock instead of
   throwing `database is locked`. Composer + PHPUnit dev setup (gitignore `vendor/`).
2. **Storage seam (`store.php`):** all SQL behind named functions; `BEGIN IMMEDIATE`
   wrapper for mutations.
3. **Domain (`api.php`):** guard helpers (`require_participant`, `assert_current_round`);
   handlers `create_room`, `join` (idempotent), `state` (blind mask + `since`-first
   short-circuit), `vote`, `reveal`, `new_round` (dedup), `set_topic`. Error envelope.
4. **Stats (`stats.php`):** pure functions per Section 6.
5. **Routing (`index.php`):** `?api=` switch, JSON encode, status + error body.
6. **Tests:** full `stats.php` suite + `api.php` suite against temp DB (blind mask,
   dedup, idempotency, short-circuit, guards). Hand-verify with curl.

**Done when:** `phpunit` green, curl walk-through works, blind mask proven in a test.

### Plan B — Frontend shell (`app.html`, `app.js`, `style.css`)

*~3.5 hr.* Builds against the API contract in Section 5 (mock responses until Plan A lands).

1. Landing + create form, deck picker (presets + custom), `create_room` → redirect.
2. Name modal; localStorage `pp_name` + `pp_token_<CODE>`; `join`.
3. Room view layout: header (name, inline topic edit, copy-link), participants
   (online dot, ✓ / value), hand (card buttons, tap-to-vote, re-tap), controls.
4. Polling loop: 1.5s `state` with `since`; skip re-render on `{unchanged}`; full
   fetch every ~10s; pause on `visibilitychange`. Results panel. Recap toggle.
5. Escape all user content on render (single escape helper). Friendly error states
   keyed off `{error, code}`. Mobile-friendly CSS.

**Done when:** drives a stubbed/real API through the full flow; errors render cleanly.

### Plan C — Integrate, polish, deploy (depends on A + B)

*~1.5 hr.*

1. Point frontend at the real endpoints; reconcile contract mismatches.
2. End-to-end polish pass: empty/error states, copy-link, inline topic edit.
3. **Deploy via explicit runtime allowlist** (outside-voice decision — "exclude vendor"
   is easy to botch). Ship a `deploy.sh` (or documented file list) that copies *only*
   the runtime files — `index.php`, `api.php`, `store.php`, `db.php`, `stats.php`,
   `app.html`, `app.js`, `style.css`, `.htaccess` — never `tests/`, `composer.*`,
   `vendor/`, or `.git`. Make `data/` writable; confirm it resolves outside web root.
4. **Smoke test:** real 2-person session; **devtools-verify votes aren't leaked before
   reveal**; verify `.db` is not downloadable; verify concurrent New Round → one round;
   confirm `☕` renders correctly end-to-end.

**Done when:** two people complete a real estimate round on the host, blind boundary
confirmed in the network tab.

Total ≈ 8–9 focused hours (A ∥ B, then C).

---

## 11. Out of scope / deferred

- **Auto-reveal (fully automatic).** The all-voted *hint* is now in scope (Section 8c),
  but auto-*revealing* without a human tap stays out — the facilitator decides when.
- Editing the voting deck after room creation.
- Per-round timer, emoji reactions, sound cues, avatars.
- Any Teams API / tab / app integration (decision 2 — URL only).
- **Storage swap (Postgres / long-lived runtime)** — not built, but the `store.php`
  seam is the place it would land. Considered and deferred per the "keep portable"
  decision: pay for the seam now, the migration later only if hosting changes.
- **Rate limiting** — unnecessary at this scale (trusted team, a dozen clients).
- **Participant removal / "kick"** — no UI; stale participants age out via cleanup.

---

## 11a. What already exists

Greenfield repo (only `README.md`). Nothing to reuse, nothing rebuilt unnecessarily.
No parallel flow to capture outputs from. All code in this plan is net-new.

---

## 11b. Failure modes (per new codepath)

| Codepath | Realistic failure | Test? | Error handling? | User sees |
|---|---|---|---|---|
| `state` blind mask | Bug serializes others' `value` pre-reveal | ✅ regression test (Plan A) | n/a (correctness) | Leak — **caught by test + smoke** |
| `new_round` concurrent | Two clicks → two rounds | ✅ dedup test | `BEGIN IMMEDIATE` + reuse-empty | One round, clean |
| `room.version` bump | Lost increment (write-write race) | ✅ via transaction test | `BEGIN IMMEDIATE` | Consistent state |
| `join` double-fire | Duplicate participant / unique error | ✅ idempotency test | upsert returns existing | Same identity |
| `vote` after reveal | Late vote mutates revealed round | ✅ test | guard → 409 `round_revealed` | Friendly error |
| SQLite write lock | Brief contention under concurrent writes | host probe | `busy_timeout=5000` waits it out | Transparent |
| WAL not honored on host | Network FS silently ignores WAL → lock errors | host probe (Plan A) | Caught before any code is written | n/a — fail fast at setup |
| `data/poker.db` exposure | DB downloadable over HTTP | smoke | `data/` outside web root (default) + `.htaccess` fallback | Blocked (verify in smoke) |
| Stale `round_id` mutation | `set_topic`/`vote`/`reveal` hits a past round | ✅ guard test | `assert_current_round` → 409 | Friendly error |
| Poll while tab hidden | Wasted requests / battery | — | pause on `visibilitychange` | Nothing |

**No critical gaps remain** (no failure mode is silent AND untested AND unhandled). Four
items the outside-voice review flagged as gaps — DB exposure, WAL feasibility on the
host, the guard-signature mismatch, and the `since`/heartbeat ordering — are now closed
in the sections above. The highest-stakes path, the blind-vote leak, has both a
regression test and a manual devtools check in Plan C.

---

## 13. Parallelization strategy

| Plan | Modules touched | Depends on |
|---|---|---|
| A — Backend + tests | `index.php`, `api.php`, `store.php`, `db.php`, `stats.php`, `tests/` | — |
| B — Frontend shell | `app.html`, `app.js`, `style.css` | API contract (Section 5), not Plan A code |
| C — Integrate + deploy | all (glue) | A **and** B |

- **Lane 1:** Plan A (independent).
- **Lane 2:** Plan B (independent — builds against the Section 5 contract with stubbed
  responses).
- **Lane 3:** Plan C (waits for both).

**Execution:** launch A and B in parallel worktrees, merge both, then run C. **No shared
module directories between A and B** → no merge-conflict risk. The only coupling is the
API contract in Section 5 — freeze it before splitting so the two lanes agree on shapes.

**Caveat (outside voice):** parallel A/B pays a rework tax — vanilla-PHP JSON shapes
drift during implementation, so Plan C's "reconcile contract mismatches" step is real
work, not a formality. If the pair is two people, parallelizing is worth it; if it's one
person, just do A then B sequentially and skip the stubbing. The split is an option, not
a mandate.

---

## 12. Defaults chosen (flag if you want any changed)

- Default deck: modified Fibonacci `1, 2, 3, 5, 8, 13, 21, ?, ☕`.
- Room code: 12-char base32, in the URL as `?room=CODE`.
- Poll interval: 1.5s, paused when the tab is hidden, version-gated re-render, full
  reconcile every ~10s.
- Presence: a participant is "online" if `last_seen_at` is within ~15s.
- Stale-room purge: 30 days, opportunistic on `create_room` (best-effort, not a
  guaranteed scheduler — if no rooms are created, old data lingers harmlessly).
- Identity: global `pp_name` + per-room `pp_token_<code>` in localStorage.
- Consensus = all cast votes identical; otherwise show the mode + spread, ties broken to
  the lower deck value.
- Reveal confirms when not everyone has voted; average shown as a secondary figure.

---

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 0 | — | not run |
| Codex Review | `/codex review` | Independent 2nd opinion | 1 | issues_found | 22 raised; cheap-fix cluster + 3 internal-contradiction fixes applied, 2 product tensions resolved by user |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 1 | CLEAR | 6 issues, all resolved; 0 critical gaps |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | — | not run |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | — | n/a (internal tool) |

- **CODEX:** Outside voice caught a real plan bug (guard signature missing `code`), a self-defeating optimization ordering (`since`/heartbeat), and a transaction-ownership leak trap — all now fixed. Plus DB-outside-webroot, WAL host-probe, code entropy, UTF-8, tie-break, reveal-confirm, demoted average, and the all-voted hint.
- **CROSS-MODEL:** Eng review and codex agreed on the concurrency and validation concerns. Two product tensions (recap-vs-hint, average prominence) went to the user: resolved as "both" and "demote + confirm."
- **UNRESOLVED:** 0.
- **VERDICT:** ENG CLEARED — ready to implement. UI scope is light (single page); a `/plan-design-review` is optional, not required.
