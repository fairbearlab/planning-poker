# TODOS

Deferred work with a future trigger. Items already documented in `docs/PLAN.md` §11
(out of scope) are not repeated here unless they carry a concrete future condition.

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
