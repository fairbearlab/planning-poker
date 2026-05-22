# Changelog

All notable changes to this project are documented here. Versions use a 4-part
`MAJOR.MINOR.PATCH.MICRO` scheme.

## [0.1.0.0] - 2026-05-21

First backend slice (Plan A): the full server for blind planning poker. No
frontend yet (Plan B) and no deploy automation yet (Plan C).

### Added
- Create a room with a custom voting deck, share a 12-char code, and join by name.
  Identity is a client-generated token, scoped per room.
- Cast and change a vote while a round is open; votes stay hidden until reveal.
- Reveal a round to show everyone's cards plus computed stats: average, consensus,
  leading estimate, range, distribution, wide-spread flag, and an "unsure" count.
- Start new rounds, edit the round topic, and review a recap of past revealed rounds.
- Live-friendly polling: `state` returns `{ unchanged: true }` when nothing changed
  since the caller's last version, with a presence heartbeat on every poll.

### Security
- The blind-vote boundary is enforced server-side: other players' values are never
  sent over the wire until reveal — the caller only ever sees their own vote.
- Mutating endpoints accept `application/json` only (a cheap CSRF guard), and all
  responses are sent `Cache-Control: no-store`.
- All SQL goes through prepared statements; room codes use a CSPRNG.

### Notes
- Runs on PHP 7.4 (PDO + SQLite, WAL). The shipped artifact is plain `.php` files;
  Composer/PHPUnit/Docker are dev-only and never deployed.
- Deferred to a wider-audience trigger (see `TODOS.md`): rate limiting, request/
  participant caps, moving the identity token out of the URL, and deploy-time checks
  (WAL on the host filesystem, DB not web-accessible).
