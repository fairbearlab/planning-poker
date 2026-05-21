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
- **Depends on:** nothing; orthogonal to the MVP build.
- **Source:** outside-voice (codex) plan review, 2026-05-21.
