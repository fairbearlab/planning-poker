# Changelog

All notable changes to this project are documented here. Versions use a 4-part
`MAJOR.MINOR.PATCH.MICRO` scheme.

## [0.2.1.0] - 2026-05-22

A one-command deploy that ships only the runtime app and nothing else (Plan C
step 3). Run `./deploy.sh /var/www/planning-poker` and you get exactly the nine
runtime files (five `.php`, plus `app.html`, `app.js`, `style.css`, and
`.htaccess`) plus a writable `data/` dir — never your tests, Composer, Vitest,
`node_modules`, or `.git`.

### Added
- `deploy.sh`: copies the runtime artifact to a target via an explicit allowlist
  (anything not named is never copied, so a new dev-only file can't silently
  ship). `./deploy.sh --list` prints what ships and what never does; the script
  ends with a deploy-time checklist (DB outside web root, WAL host probe,
  confirm the DB isn't downloadable, two-person smoke test).
- Redeploy is now safe on a reused target: stale top-level files left by a prior
  deploy (`vendor/`, `tests/`, `composer.json`, `.git/`, …) are pruned before
  copying, while `data/` is preserved so the live SQLite DB survives.

### Security
- The deploy ships `data/.htaccess` (the `Require all denied` DB fallback) into
  the target, not just the root `.htaccess`, so the SQLite file's defense-in-depth
  deny rule actually lands on the host.
- The "don't deploy onto the source tree" guard compares physical paths
  (`pwd -P`), so a symlinked target can't slip past it.

## [0.2.0.0] - 2026-05-22

The browser frontend (Plan B): the planning-poker app you actually use, not just
the API. Open a link, pick a deck, and vote with your team in real time.

### Added
- A single-page web app (`app.html` + `app.js` + `style.css`, no build step, no
  dependencies): create a room and pick a deck (Fibonacci, T-shirt, powers of two,
  or a custom comma-separated deck), then share the room link.
- Join by name; your name and a per-room identity token are remembered in
  `localStorage`, so re-opening the link drops you straight back in.
- Tap a card to vote, tap again to change it before reveal; the selection updates
  instantly. Reveal shows everyone's cards with the consensus/leading estimate up
  front and the average kept secondary so it doesn't anchor the room.
- Live updates by polling every 1.5s (skipping re-renders when nothing changed),
  with presence dots that pause while your tab is hidden and refresh on return.
- Inline topic editing, a copy-link button, a "new round" control, an
  "everyone's in — reveal?" nudge, and a collapsible recap of past rounds.

### Security
- All server- and user-supplied text is HTML-escaped before rendering, including in
  attribute context — a deck value like `5"` can no longer break out of an
  attribute to inject markup (DOM-based XSS).
- API calls and shared links are directory-relative, so opening the app as
  `/app.html` directly can't misroute requests away from the front controller.
- Corrupt or non-UUID identity tokens in `localStorage` are regenerated instead of
  being sent to (and rejected by) the server.

### Accessibility
- Online/offline presence dots carry text labels (not color alone), the live vote
  tally announces changes, secondary controls meet the 44px touch-target minimum,
  and motion respects `prefers-reduced-motion`. Dark mode is fully themed.

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
