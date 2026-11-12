# Mission Control

A local review queue for AI coding agents. You describe a task; a Codex worker implements it in an isolated Git worktree, runs your tests, captures before/after screenshots, and gets an independent review. The finished package lands in one queue where you approve, request changes, defer, or reject. Approved work can then be merged locally and published through pull requests to staging and production.

Inspired by the agent setup [Brian Armstrong described on X](https://x.com/brian_armstrong/status/2119112609223827547).

It is a Laravel dashboard plus a small TypeScript worker. It runs on your own machine, accepts loopback connections only, and is not intended for public hosting.

## Install

Requirements: PHP 8.3+, Composer, Node 22+, npm, Git, and either SQLite or PostgreSQL. Publishing also needs the GitHub CLI (`gh`), signed in with access to your project's repository.

```sh
cp .env.example .env
composer run setup
composer run start
```

Open **http://127.0.0.1:8765**. This starts the web app and a single queue listener; keep the terminal running. For frontend development with Vite hot reload, use `composer run dev` instead.

`.env.example` defaults to SQLite. For PostgreSQL, create a dedicated database and set the usual `DB_*` variables. Never point this dashboard at your project's own application database.

Setup installs Playwright's Chromium browser for screenshots. To reinstall it separately, run `npm --prefix worker run browser:install`. Linux installations may also need Playwright's documented system dependencies.

## Configuration

Put `OPENAI_API_KEY` in your local `.env` file. Never paste it into a task or commit it. The dashboard only displays whether a key is present. `.env` is ignored by Git.

The Settings screen controls the project repository path, base branch, model, test command, time limit, token threshold, and daily run allowance. A blank model uses the Codex SDK's default. Test commands are JSON argument arrays executed without a shell, for example:

```json
["php", "artisan", "test", "--compact"]
```

Each task starts from the configured branch's **local committed revision**. Uncommitted edits are not part of that snapshot. Point Mission Control at a clean checkout dedicated to it, separate from the one you work in by hand.

Publishing is configured in `.env`:

| Variable | Purpose |
|---|---|
| `MISSION_GITHUB_REPOSITORY` | The only GitHub repository (`owner/name`) tasks may be published to. Leave blank to disable publishing. |
| `MISSION_DEPLOY_WORKFLOW` | The GitHub Actions workflow file that deploys after a merge. Defaults to `deploy.yml`. |
| `MISSION_STAGING_URL`, `MISSION_PRODUCTION_URL` | Links shown after a deployment succeeds. |
| `MISSION_PRODUCTION_CHECKS` | Checks a production release must pass locally, as JSON: `{"tests":{"directory":".","command":["npm","test"]}}`. |

The publishing workflow assumes a `staging` branch that deploys to a staging site and a `main` branch that deploys to production.

The queue listener starts a fresh PHP process for each job, so changes to `.env` take effect for subsequent jobs. Do not cache configuration for local development.

## Task workflow

1. Create a task with a request and acceptance criteria.
2. Start the task. Only one task runs at a time; its settings are saved with the run.
3. The worker creates a separate Git worktree, captures the starting pages, runs the Codex implementation, captures the changed pages, executes the configured tests, and requests an independent read-only Codex review with the screenshots.
4. Inspect the recorded result, paired before/after screenshots, working preview, test output, review findings, and exact revision.
5. Approve the revision, request changes with feedback, defer it, or reject it.
6. After approval, use the separate local merge action. Merging requires an unchanged reviewed revision and a clean checkout on the expected base branch. This step changes only the local checkout.
7. To publish, use **Publish approved change** on the merged task. Mission Control pushes the reviewed task branch and opens its pull request into `staging`. **Merge into staging** becomes available when the required checks and branch protections permit it; the server verifies the head and base again before merging.

Task worktrees and evidence are retained under `storage/app/`. Task worktrees do not receive the source checkout's `.env`, installed dependencies, or uncommitted files. Missing dependencies or failing tests must be resolved before a change can pass review.

Decision feedback is kept in the judgment history. It is not silently converted into universal preferences.

The review queue and task pages update automatically while visible and pause in hidden tabs. Filters, pagination, and unfinished drafts stay intact.

If a process crashes, `php artisan mission:recover` marks stale runs as interrupted after checking that their worker is no longer alive. Evidence is retained and a retry requires an explicit action. Retries start a fresh worktree and include previous feedback; they do not resume an incomplete Codex turn.

## Keep the base current

The repository panel on the queue and Settings screens shows the local and upstream commits, ahead/behind counts, uncommitted files, and the last remote check. **Check remote** fetches the configured upstream without changing checked-out files. **Sync** fast-forwards a clean checkout after checking the displayed revision again. It refuses dirty checkouts, the wrong branch, local commits ahead of upstream, divergent history, ignored-file collisions, and active task runs; it never stashes, resets, or resolves conflicts automatically.

Merging a reviewed task fetches its upstream again and requires the upstream commit to match the task's recorded base. If the base advanced, the task needs a new run and review.

## Publish to staging

Mission Control uses your existing `gh` login and excludes application secrets from Git and GitHub subprocesses. The destination is limited to the configured repository's `staging` branch; it does not push directly to a protected branch or bypass required checks.

The staging panel records the pull request, required checks, merged revision, and deployment result for that exact revision. A merged pull request does not by itself prove that the site deployed successfully, so the panel follows the deployment workflow too.

## Publish to production

The separate production panel starts with a release plan after the task has deployed successfully to staging. Choose the reviewed task's changes, with any explicitly selected prerequisite files, or the complete staging snapshot verified for that task. Inspect the pinned source and production revisions and the complete changed-file list before publishing. Planning is local; it does not push or deploy.

The release commit has the captured production commit as its parent. Later staging changes are not added to the saved plan, and a change to production invalidates it. Only one production release can be open at a time.

Publish the reviewed plan as a draft PR, verify that exact release revision locally against `MISSION_PRODUCTION_CHECKS`, then mark it ready. Required GitHub checks and branch protections must pass before **Merge into production** becomes available. Automatic status checks never publish or merge.

## Screenshots and working previews

Settings lets you enable capture and choose up to five page paths, the app folder, server command, and preview lifetime. Each page gets before/after desktop and mobile PNGs. The saved revision and image hashes bind these captures to the reviewed code; required missing, failed, or stale evidence prevents approval.

The working preview serves a separate snapshot of that exact revision on a local port. It expires after 30 minutes by default and can be stopped or restarted from the review card.

The Laravel preview driver prepares the app folder, installs or copies matching dependencies, creates a fresh database, runs migrations and seeders, and builds frontend assets. The `MISSION_PREVIEW_DB_*` settings choose SQLite or a local PostgreSQL role that can create and drop disposable databases. For other stacks, the command driver runs the configured command against a revision snapshot; `{port}` is replaced with its allocated loopback port.

## macOS launcher

An optional native launcher starts and stops the local services without a terminal:

```sh
npm run desktop:build
npm run desktop:install
```

The source is in `resources/desktop`. Installation places **Mission Control** in `~/Applications` with a Desktop shortcut. The app depends on this checkout staying at its path and does not copy your API key into the app bundle. Closing the launcher leaves the services running; **Stop services** stops them only when no work is queued or active.

## Operating limits

This is a prototype. It bounds run duration, daily run count, concurrency, and automatic retries. The token threshold is checked after Codex turns; it is **not a hard token or dollar spending ceiling**. There is no autonomous scheduled work.

## Verify

```sh
composer test
npm run test:frontend
npm run test:worker
npm run build
```

The automated tests use a separate in-memory database and temporary Git fixtures. They do not spend API credits or touch your project.

Built with the [Codex TypeScript SDK](https://learn.chatgpt.com/docs/codex-sdk), [Laravel](https://laravel.com/docs/13.x/installation), and [Laravel queues](https://laravel.com/docs/13.x/queues).

## License

Released under the [MIT License](LICENSE).
