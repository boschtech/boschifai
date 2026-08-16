# Boschifai

Boschifai takes a plain-text requirement, runs it through AI-assisted gap analysis and a
testability score, and — after a human approves — generates a test plan, test cases, and
PHPUnit Feature test code, runs it locally, and (after a second human approval) opens a real
GitHub pull request and reports back a composite confidence score once CI concludes.

**MVP scope:** one test type (PHPUnit Feature tests) only. Target repos are connected via
"Connect Repo" (`resources/js/pages/GithubSettingsPage.vue`) — either a GitHub account's repos
(OAuth), or a repo already checked out on disk. Each connected repo gets exactly ONE persistent
checkout that every Run against it shares (see `app/Services/Sandbox/RepoCheckoutManager.php`) —
a GitHub-connected repo is cloned into `var/boschifai/repos/<name>` and kept in sync per run; a
local repo is used directly at its own path and is never cloned, reset, or otherwise modified in
place. Because the checkout is shared, only one Run per repo may be in flight at a time
(`RunController::store()` rejects a second one with a 422). See `app/Enums/RunState.php` for the
full pipeline state machine and `config/boschifai.php` for every configurable knob.

Generation is done by driving **headless Claude Code** (`claude -p "/boschifai-review ..."` etc.)
against the real `.claude/commands/boschifai-*.md` prompts inside that checkout — not by calling
the `boschifai` CLI, which does not perform real AI generation for its local-file commands (see
the class docblocks under `app/Services/ClaudeRunner/` for why). The generated test is then run
directly in that same checkout via the worker's own PHP/Composer — no Docker, no per-repo
test-runner image (see `app/Services/TestExecution/LocalTestRunner.php`); this only works because
every connected repo is assumed PHPUnit/Laravel-shaped, matching this MVP's own scope.

## Prerequisites

Two ways to run this: bare-host (needs everything below installed locally) or
[Docker](#running-with-docker) (needs only Docker — the `boschifai` CLI it depends on is vendored
into this repo at `docker/boschifai-cli/` and built as part of the image).

- PHP 8.2+, Composer
- Node.js + npm
- Git
- The `claude` CLI, installed and logged in (used non-interactively — no `ANTHROPIC_API_KEY`
  is required locally if `claude` already has its own stored credentials; production should
  source one via AWS SSM per `config/boschifai.php`'s TODO comments)
- The `boschifai` CLI, built from the source vendored at `docker/boschifai-cli/` (`cargo build --release
  --package boschifai-cli` from that directory) and on your `PATH` — `boschifai init` is what materializes
  `.claude/commands`/`.claude/skills` into a fresh worktree; it is required even though `boschifai`
  itself doesn't do the generation. Docker mode builds this for you automatically; bare-host
  mode does not.
- A registered GitHub OAuth App (`BOSCHIFAI_GITHUB_OAUTH_CLIENT_ID`/`_SECRET`, see
  `.env.example`) — only needed for the GitHub-connected half of "Connect Repo"; connecting a
  repo already checked out on disk needs no OAuth App at all
- Whatever a connected repo's own test suite needs at runtime (its own `composer install` +
  `php artisan test`, run directly against the checkout — see `LocalTestRunner`)

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Set BOSCHIFAI_GITHUB_OAUTH_CLIENT_ID / _SECRET (see .env.example) before you'll be able to
# connect a real GitHub account and select repos from the UI.

php artisan migrate          # creates the sqlite DB — no seeding needed, repos come from OAuth
npm run build                # or `npm run dev` for a live-reloading Vite dev server
```

## Running the app

```bash
php artisan serve
```

Then open the URL it prints. The Vue app is a single-page app served from one Laravel route
(`routes/web.php`'s catch-all → `resources/views/app.blade.php`); the API lives under `/api/*`
(`routes/api.php`).

### The queue worker

Every pipeline step (gap analysis, test-case generation, code generation, local execution,
push, CI polling) runs as a queued job, so nothing happens until a worker is running:

```bash
php artisan queue:work
```

**Do not run `queue:work` with its default settings and assume it's fine** — Claude-invoking
jobs can take up to ~15 minutes (`config('boschifai.claude.timeouts.*')`), and Laravel's queue
worker kills jobs after 60 seconds by default. Every job that needs longer already overrides
its own `$timeout`/`$tries` properties (see `app/Jobs/*.php`), so a plain `php artisan
queue:work` respects those per-job overrides correctly — just don't override them back down
with a global `--timeout` flag lower than a job's own `$timeout`.

## Running tests

```bash
php artisan test
```

`phpunit.xml` runs tests against an in-memory sqlite database, isolated from your local dev
database (`database/database.sqlite`) — this was previously misconfigured (commented out, per
Laravel 11's own scaffold default) and would silently wipe real local data every time the test
suite ran. If you ever see your local Runs disappear after running tests, check that
`DB_CONNECTION`/`DB_DATABASE` are still uncommented in `phpunit.xml`.

## Running with Docker

`docker-compose.yml` runs the app (`app`), the queue worker (`worker`), and MySQL (`db`).

```bash
docker compose up -d --build
```

That's it — `app` and `worker` each run their own `php artisan migrate --force` on every
start (idempotent, safe if both race on first boot; `restart: unless-stopped` retries the
rare loser). No seeding: visit `http://localhost:8420`, use "Connect Repo" to authorize a
GitHub account (or browse to a repo already checked out on disk) and pick repos.

The Dockerfile **compiles a Linux `boschifai` from source** as a build stage (`docker/app/Dockerfile`'s
`boschifai-builder` stage, source vendored at `docker/boschifai-cli/`) — a `boschifai` binary built for your host is
not portable into a Linux container, so this happens automatically on every image build with no
path configuration needed.

Set these in `.env` before building (see `.env.example` for the full list with explanations):

- `ANTHROPIC_API_KEY` — required for headless `claude -p` to authenticate inside a container
  (there's no interactive login possible there). Get one from the Claude Console.
- `BOSCHIFAI_GITHUB_OAUTH_CLIENT_ID` / `BOSCHIFAI_GITHUB_OAUTH_CLIENT_SECRET` — identify this
  app's GitHub OAuth App (a one-time, out-of-band registration — see `.env.example`'s comment
  for the exact steps). Without these, connecting via GitHub 422s immediately with a clear
  message — connecting a local repo doesn't need these at all.
- `BOSCHIFAI_LOCAL_REPOS_ROOT` — the host directory "Connect Repo"'s local-filesystem section
  is allowed to browse (bind-mounted read-only into `app`/`worker`).

### Local test execution

`LocalTestRunner` runs `composer install && php artisan test` directly against the run's repo
checkout, using the `worker` container's own PHP/Composer — no Docker, no per-repo test-runner
image, no Docker-outside-of-Docker socket. This is deliberate, not a fallback: every connected
repo is assumed PHPUnit/Laravel-shaped (this MVP's own scope), so the same toolchain `worker`
already needs to run Boschifai itself is sufficient for the target repo too.

### `auth.json` and other gitignored files

A private Composer package pulled over SSH and authenticated via a gitignored `auth.json` is a
real, previously-hit failure mode — like `.claude/commands`, such files are invisible to a fresh
GitHub-connected checkout (git only carries tracked files), and `composer install` fails without
them. `RunWorkspaceManager` copies whatever's listed in a `RepoConfig` row's own
`copy_untracked_files` column from `git_remote_path` into the checkout (a no-op for a local repo,
whose checkout already has these files for real). There's no UI for this yet — set it directly on
the row (`php artisan tinker`) if a connected repo needs it.

## Troubleshooting

If a submitted requirement seems to do nothing, or the page is blank/broken, check these in
order — each was a real bug hit once, fixed, and left here so the fix isn't lost:

1. **Run detail page shows nothing changing.** Confirm a worker is actually processing jobs:
   bare-host, is `php artisan queue:work` running in a terminal? Docker, does `docker compose
   ps` show `worker` as `Up` (not restarting)? The live activity panel on the run page should
   now say explicitly "Queued, but no worker has picked this up yet" if this is the issue — if
   you still see a blank/static page instead, that's a regression in that feature, not this
   root cause.
2. **Blank white page, nothing renders.** Open the browser console. `Failed to load module
   script ... MIME type of "text/html"` means static assets are being routed through the SPA
   catch-all instead of served directly — check `docker-compose.yml`'s `app` command still uses
   `docker/app/router.php`, not `public/index.php` directly or a bare `artisan serve`.
   `Cannot read properties of undefined (reading 'dispatch')` means Vuex didn't attach —
   confirm `node_modules/vuex/package.json` says a `3.x` version, not `4.x` (Vuex 4 targets Vue
   3 and silently no-ops against Vue 2's `Vue.use()` API).
3. **Docker web app and worker seem to disagree about what data exists.** Confirm
   `docker-compose.yml`'s `app`/`worker` commands invoke `php -S` directly, not `php artisan
   serve` — in this stack, `artisan serve`'s spawned child process was confirmed to silently
   ignore the container's `DB_CONNECTION=mysql` override and fall back to `.env`'s committed
   `sqlite` value, so requirements submitted through the web UI landed in a different database
   than the one the worker polls for jobs.
4. **A Claude step fails instantly with `"Not logged in · Please run /login"`.**
   `ANTHROPIC_API_KEY` isn't reaching the worker — the containerized `claude` CLI has no
   interactive login, unlike your host machine's. Set it in `.env` and restart the worker.
5. **A Claude step fails instantly with `"Credit balance is too low"`.** Not a code issue — the
   Anthropic account behind that API key needs credits/billing set up in the Claude Console.

## What's not wired up yet

These are real TODOs, not silently-stubbed behavior — the code paths that need them fail with
a clear error rather than pretending to succeed:

- **GitHub push/PR/CI polling** — works once a repo is connected via "Connect GitHub" (a
  classic OAuth App, see `GithubOAuthService`); `BOSCHIFAI_GITHUB_OAUTH_CLIENT_SECRET` should
  be sourced from AWS SSM in production, never a plain `.env` value.
- **Detecting a revoked/uninstalled GitHub connection** — there's no webhook receiver, so a
  user revoking access on GitHub's side (github.com/settings/applications) isn't noticed here
  until the next attempted use of that connection fails.
- **Claude container sandboxing** — `HeadlessClaudeInvoker` currently execs `claude` directly
  inside whatever container/host is running the queue worker, with the same credentials as
  everything else in that container. Production should run it in a more locked-down container
  with network egress restricted to `api.anthropic.com` and no GitHub/AWS credentials present
  (see that class's docblock) — today's Docker setup does not attempt this isolation.

## Project layout

- `app/Models` / `database/migrations` — the Run state machine and its related records
  (steps, Claude invocations, artifacts, human approvals, execution/CI results)
- `app/Services/Sandbox` — per-repo checkout management (clone/fetch for GitHub-connected repos,
  a no-op for local ones) and per-run workspace prep (`boschifai init`, untracked-file copying)
- `app/Services/ClaudeRunner` — headless Claude Code invocation, prompt building, transcript parsing
- `app/Services/ArtifactCollection` — git-status-diff based artifact discovery, testability score parsing
- `app/Services/TestExecution` — local PHPUnit execution, directly via the worker's own PHP/Composer
- `app/Services/Github` — branch/commit/PR/CI-polling
- `app/Services/Confidence` — the composite confidence score calculator
- `app/Jobs` — one queued job per pipeline stage
- `resources/js` — the Vue 2 frontend (components under `components/`, pages under `pages/`)
- `docker/`, `docker-compose.yml` — see [Running with Docker](#running-with-docker)
