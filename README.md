# Blind Planning Poker

A tiny, zero-dependency planning-poker app for estimating work as a team. Votes
stay **hidden until everyone reveals**, so nobody anchors on the first number.

The shipped artifact is plain PHP files (PDO + SQLite, WAL) — no framework, no
runtime dependencies. Composer, PHPUnit, and Docker are dev-only and never deployed.

## Status

Phase A (backend) and Phase B (browser frontend) are built — the full server with
its PHPUnit suite, plus the single-page web app (`app.html` + `app.js` + `style.css`)
covered by a Vitest/jsdom test harness. Deploy automation (Plan C) is not built yet.
See [docs/PLAN.md](docs/PLAN.md) for the full design and [TODOS.md](TODOS.md) for
deferred work.

## How it works

- Create a room with a custom deck, share the 12-char code, join by name.
- Vote and re-vote while a round is open; values are masked server-side until reveal.
- Reveal to show all cards plus stats: average, consensus, leading estimate, range,
  distribution, wide-spread flag, unsure count.
- Start new rounds, edit topics, and review a recap of past revealed rounds.

The API surface lives at `index.php?api=<name>` (JSON in / JSON out). See PLAN §5.

## Development

Everything runs in Docker on PHP 7.4 (the production target). **Never use host
`php`/`composer`/`phpunit`** — see [CLAUDE.md](CLAUDE.md) for why.

```bash
docker compose run --rm test     # run the PHPUnit (backend) suite
docker compose up web            # serve the app at http://localhost:8080/app.html
```

The frontend has its own dev-only test harness (Vitest + jsdom), run on the host:

```bash
npm install                      # one-time, installs vitest + jsdom
npm test                         # run the frontend test suite
```
