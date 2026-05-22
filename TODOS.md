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

## Test & code-quality scaffold (post-MVP growth)

Deferred tiers from the testing review (2026-05-22). Tier 1 — `bats` tests +
`shellcheck` for `deploy.sh` — is **done** (see `tests/deploy/deploy.bats`,
`docker compose run --rm shell-test` / `shellcheck`). The rest are sized for "as
the project grows past MVP." All are dev/CI-only — none add a runtime dependency
on the prod host (no npm/composer there).

### Tier 2 — make coverage measurable

- **What:** Wire up coverage for both suites so the "100% coverage is the goal"
  line in `CLAUDE.md` becomes enforced, not aspirational. Today no coverage driver
  is installed (PHP: no pcov/xdebug in the dev image; JS: no `@vitest/coverage-v8`).
- **Why:** Both suites are green and look thorough, but nothing reports what slipped
  through. You can't defend a coverage goal you don't measure.
- **Where to start:**
  - PHP: add **pcov** to `docker/Dockerfile.dev` (fast, PHP-7.4-compatible — proves
    coverage on the *prod* runtime version), run `phpunit --coverage-text`, set a
    `<coverage>` floor in `phpunit.xml`.
  - JS: `npm i -D @vitest/coverage-v8`, add a `coverage.thresholds` block to
    `vitest.config.js`.
- **Trigger:** next time test gaps bite, or when onboarding a second contributor.
- **Source:** testing review, 2026-05-22.

### Tier 2 — Playwright end-to-end smoke layer

- **What:** A *thin* Playwright suite (dev/CI-only) that drives the real app via
  `docker compose up web` — not a replacement for the Vitest units, a complement.
- **Why:** The 39 Vitest tests run `app.js` in jsdom with a **mocked** `fetch`, so
  they never touch the real PHP backend, real SQLite, or a real browser. Three things
  are structurally untestable today: (1) the **blind-vote boundary end-to-end** —
  currently verified server-side and client-side *separately*, with the real
  cross-the-wire check left as a manual devtools step (PLAN §10a Plan C step 4);
  (2) **contract drift** between `app.js` and `api.php` shapes (the "reconcile
  mismatches" risk jsdom mocks hide); (3) **multi-client integration** (polling
  short-circuit, presence dots, reveal propagating to a 2nd client).
- **Where to start:** scope to the 2-person flow + a network-tab assertion that a
  second participant's `value` is `null` in the `state` response pre-reveal — turning
  the highest-stakes correctness property from manual smoke into a CI gate.
- **Trigger:** before the first non-trivial frontend refactor, or when contract drift
  actually bites during integration.
- **Source:** testing review, 2026-05-22 (user-flagged).

### Tier 3 — CI + lint gate

- **What:** A GitHub Actions workflow running `docker compose run --rm test`,
  `npm test`, `shellcheck`, and the `bats` suite on every PR; plus static analysis.
- **Why:** No `.github/` exists — both suites are green only because they're run by
  hand, and the PHP-7.4-parity guarantee depends on a human remembering to use Docker.
  A workflow using the same `php:7.4-cli` image locks parity in automatically.
- **Where to start:**
  - CI: matrix the four test commands; CI is dev-side so npm/composer there is fine.
  - **PHPStan** (or Psalm) for PHP static analysis — catches type bugs and 8.x-only
    syntax statically, reinforcing the 7.4-compat rule `CLAUDE.md` cares about.
  - **ESLint** for `app.js`; optionally **PHP-CS-Fixer** + **Prettier** for style as
    more hands touch the code.
- **Trigger:** when a second contributor joins, or the first "tests were red on main"
  surprise — whichever comes first.
- **Source:** testing review, 2026-05-22.

## Completed

### Deploy allowlist must exclude JS dev tooling

- `deploy.sh` ships only the runtime allowlist (`index.php`, `api.php`, `store.php`,
  `db.php`, `stats.php`, `app.html`, `app.js`, `style.css`, `.htaccess`) and explicitly
  excludes the JS dev tooling (`package.json`, `node_modules/`, `vitest.config.js`,
  `tests/js/`) alongside `tests/`, `composer.*`, and `vendor/`. On a reused target,
  non-allowlisted top-level files are pruned (with `data/` preserved). Verified by a
  deploy into a seeded temp dir — no dev files landed.
- **Completed:** v0.2.1.0 (2026-05-22)
