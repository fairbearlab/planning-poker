# TODOS

Deferred work with a future trigger. Items already documented in `docs/PLAN.md` §11
(out of scope) are not repeated here unless they carry a concrete future condition.

## WAL / busy_timeout host probe (deploy-time)

- **What:** Confirm SQLite WAL journaling actually sticks on the production host's
  filesystem and that `busy_timeout` absorbs concurrent writes instead of throwing
  `database is locked`.
- **Why:** Plan §10a step 1. WAL pragmas are set in code (`db.php`) and a 5-process
  concurrency test passes locally, but some shared/network filesystems silently fall
  back from WAL — which only shows up on the real host.
- **Trigger (do this when):** Plan C deploy, before the first real multi-person session.
- **Where to start:** open the DB on the host, set `journal_mode=WAL`, read it back, then
  fire two concurrent writes and confirm `busy_timeout=5000` (`db.php`) absorbs the lock.
- **Source:** ship plan-completion audit, 2026-05-21.

## Rate limiting + revisit no-auth model

- **What:** Add request rate limiting and reconsider the "room code is the only gate"
  security posture.
- **Why:** The MVP deliberately skips both — justified only because it's internal,
  trusted-team use (a dozen known people, low stakes). That justification evaporates the
  moment the app is exposed to a wider/public/multi-org audience.
- **Trigger (do this when):** the app leaves the trusted-team setting — public URL,
  multiple orgs, untrusted participants, or anyone reports a probing/abuse attempt.
- **Where to start:** room codes are already 12-char base32 (~60 bits, see plan §4), so
  enumeration is hard but not throttled. Add a simple per-IP token bucket on
  `create_room`/`join`; revisit whether `client_token` identity needs server-issued
  tokens instead of client-generated ones.
- **Also revisit (same trigger), from the ship adversarial review (2026-05-21):**
  - **Token in URL.** `state`/`recap` are GET with `client_token` in the query string, so
    it lands in access/proxy logs and browser history (a scraped token replays `state` and
    reveals that holder's own pre-reveal vote). Mitigated for now with `Cache-Control:
    no-store`. When leaving trusted-team, move the token to an `X-Client-Token` header (or
    make these POST) and update the plan §5 contract + tests.
  - **No request size / participant caps.** `index.php` reads `php://input` with no body
    limit; `create_room`/`join` have no participant cap. Add a max body size and a
    per-room participant cap alongside rate limiting.
  - **Heartbeat write amplification.** `api_state` writes (`touch_participant`) on every
    poll, contending on SQLite's single writer. Fine for a dozen pollers; if scaling up,
    throttle the heartbeat (only write when `last_seen_at` is older than a few seconds).
- **Depends on:** nothing; orthogonal to the MVP build.
- **Source:** outside-voice (codex) plan review + ship adversarial review, 2026-05-21.

## Deploy allowlist must exclude JS dev tooling (deploy-time)

- **What:** When Plan C ships the `deploy.sh` runtime allowlist, make sure it copies the
  frontend runtime files (`app.html`, `app.js`, `style.css`) but EXCLUDES the dev-only
  JS test tooling: `package.json`, `node_modules/`, `vitest.config.js`, and `tests/js/`
  — the same way it already excludes `tests/`, `composer.json`, and `vendor/`.
- **Why:** Phase B added a Vitest+jsdom frontend test harness. The `.htaccess` front
  controller serves real static files directly, so any of these left in the web root on a
  host without the allowlist could be fetched over HTTP. They are gitignored
  (`node_modules/`, `package-lock.json`) but `package.json`/`vitest.config.js`/`tests/js/`
  are committed and must be filtered at deploy time.
- **Trigger (do this when):** Plan C deploy — writing `deploy.sh` / the runtime file list.
- **Where to start:** the explicit runtime allowlist in `deploy.sh` (Plan §10a Plan C step 3).
- **Source:** ship review, 2026-05-22.

## DB must not be web-accessible on the deploy host (deploy-time)

- **What:** Confirm the SQLite file and its `-wal`/`-shm` sidecars cannot be fetched over
  HTTP on the actual host. The DB holds every `client_token` and all (pre-reveal) votes.
- **Why:** The `.htaccess` deny rules only work on Apache hosts that honor overrides. On
  Nginx or a misconfigured host, `/data/poker.db` could be downloadable. The real defense
  is `POKER_DB_PATH` pointing outside the web root.
- **Trigger (do this when):** Plan C deploy.
- **Where to start:** set `POKER_DB_PATH` outside the web root; then `curl` the DB path and
  WAL sidecar and confirm 404/403. Pair with the WAL host probe above.
- **Source:** ship adversarial review (codex), 2026-05-21.
