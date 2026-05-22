# Blind Planning Poker

Zero-dependency PHP backend (plain PHP files, PDO + SQLite). The shipped artifact
is just the runtime `.php` files; Composer, PHPUnit, and Docker are dev-only and
never deployed. See `docs/PLAN.md` for the full design.

## Running PHP — always through Docker

**Every PHP invocation for this project runs in Docker on PHP 7.4. Never call host
`php`, `composer`, or `vendor/bin/phpunit` directly.** The production host is PHP 7.4;
this dev machine is 8.3, so host runs can mask 7.4 regressions (and surface 8.x-only
behavior that won't exist in prod). The `php:7.4-cli` image is the source of truth.

```bash
docker compose run --rm test                       # full PHPUnit suite (composer install + phpunit)
docker compose run --rm dev vendor/bin/phpunit      # phpunit with custom args/filters
docker compose run --rm dev composer <args>         # composer (install, require, dump-autoload, ...)
docker compose run --rm dev php <script-or-flags>   # any one-off php invocation
docker compose up web                               # serve at http://localhost:8080 for manual/curl checks
```

Rule of thumb: if a command starts with `php`, `composer`, or `phpunit`, prefix it with
`docker compose run --rm dev`. The `dev` service mounts the repo at `/app`, so file paths
are identical inside and out.

## Frontend tests — Vitest on host Node

The frontend (`app.js`) is vanilla browser JS with no build step. Its tests run in
**jsdom on host Node** (not Docker): there is no JS runtime in production, so the
PHP-7.4-parity reason for Docker doesn't apply. The harness loads the real `app.js`
into the real `app.html` DOM and drives it through DOM events with a mocked `fetch`.

```bash
npm install         # one-time: installs vitest + jsdom (dev-only, gitignored)
npm test            # run the frontend suite once (vitest run)
npm run test:watch  # watch mode
```

`package.json`, `node_modules/`, `vitest.config.js`, and `tests/js/` are dev-only and
must stay out of the runtime deploy allowlist (alongside `tests/`, `composer.json`,
`vendor/`).

Test expectations:

- 100% coverage is the goal. New function → test it. Bug fix → regression test.
  New conditional → test both paths. New error path → a test that triggers it.
- PHP changes: never commit code that fails the Docker suite.
- Frontend changes: never commit code that fails `npm test`.

## Skill routing

When the user's request matches an available skill, invoke it via the Skill tool.

- Bugs/errors → /investigate
- Code review/diff check → /review
- Ship/deploy/PR → /ship
- QA a running site → /qa
