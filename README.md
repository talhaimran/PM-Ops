# PmOps

A lightweight, self-hosted, Jira-like project tracker that people use through a web UI and AI agents use through an MCP server and a documented REST API.

- **Workspaces** hold projects and people, with roles Owner, Admin and Member, an audit log, plan limits, two-factor authentication and Google or GitHub sign-in. Every page lives under the workspace's address (`/acme/projects/WEB`).
- **Projects** with short keys (`WEB`) and issues numbered per project (`WEB-12`). Roles per project: Admin, Member, Viewer.
- **Issues:** Task, Bug, Story and Epic types, priorities, assignee, labels, story points, due dates, epics and one level of sub-tasks. Descriptions and comments are Markdown, and an activity log records who changed what and whether it came via web, API or MCP (with the token name).
- **Kanban board** with drag and drop and per-project columns. Each column shows one or more **statuses** from the workspace's status library; a status has a category (To do, In progress, Done), and Done means "category Done", wherever its column sits. **Backlog** with drag-to-rank. **Sprints:** create, start and complete, with unfinished issues moved to the backlog or the next sprint.
- **List view**, cross-project **search**, and a **dashboard** with my issues, status counts and sprint progress.
- **For agents:** 38 MCP tools over HTTP or stdio, a REST API with OpenAPI docs, agents with read, write or delete levels and project-scoped tokens, Idempotency-Key, per-agent rate limits, bulk create/update, and errors that list the valid options.

See [AGENTS.md](AGENTS.md) for how agents should use it. [SPEC.md](SPEC.md) is the product spec, and [PROGRESS.md](PROGRESS.md) logs the decisions made while building it.

## Requirements

- PHP 8.3+ (tested on 8.5) with `pdo_mysql`, `mbstring`, `intl`, `bcmath`
- Composer 2
- MySQL 8+
- Node 20+ and npm

On macOS: `brew install php composer mysql node && brew services start mysql`.

## Setup

```bash
git clone <this repo> PM-Ops && cd PM-Ops
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan passport:keys    # signing keys for MCP clients' OAuth tokens (storage/oauth-*.key, not committed)
```

Create the databases and a user that matches `.env` (`pm_monkey` / `pm_monkey` by default; change both for anything beyond local use):

```bash
mysql -uroot -p -e "CREATE DATABASE pm_monkey; CREATE DATABASE pm_monkey_test;
  CREATE USER 'pm_monkey'@'localhost' IDENTIFIED BY 'pm_monkey'; CREATE USER 'pm_monkey'@'127.0.0.1' IDENTIFIED BY 'pm_monkey';
  GRANT ALL ON pm_monkey.* TO 'pm_monkey'@'localhost'; GRANT ALL ON pm_monkey_test.* TO 'pm_monkey'@'localhost';
  GRANT ALL ON pm_monkey.* TO 'pm_monkey'@'127.0.0.1'; GRANT ALL ON pm_monkey_test.* TO 'pm_monkey'@'127.0.0.1';"
```

Migrate and load the demo workspace:

```bash
php artisan migrate:fresh --seed
```

The seed prints a **demo API token** (for the "Demo token" agent) and creates workspace **Demo** at `/demo`:

| Login | Password | Workspace role | Role in WEB |
|---|---|---|---|
| demo@example.com | password | Owner | Admin |
| alex@example.com | password | Admin | Member |
| sam@example.com | password | Member | Viewer |

It also creates project **WEB** (about 30 issues, labels, epics, sub-tasks, an active sprint and a planned one), a "Demo token" agent at Read and write, and one pending approval (the agent asks to move WEB-5 to Done) that demo@example.com can decide on the dashboard. Use `php artisan migrate` without `--seed` for an empty install, then register at `/register`: the first person creates the workspace and becomes its Owner.

### Editions

`PM_EDITION` picks how sign-up works:

- **`self_hosted`** (the default): one workspace per install. The first person to register creates it; everyone after needs an invitation from an Owner or Admin. There are no plan limits unless you set them.
- **`cloud`**: anyone can sign up and gets their own workspace, can belong to several and switches between them from the top bar. New workspaces start on the free plan limits (2 billed users, 3 viewers, 1 project, 60 API requests per token per minute and 2,000 per day).

Change a workspace's limits with `pm:entitlements`:

```bash
php artisan pm:entitlements demo --list                 # every limit, its value and where it came from
php artisan pm:entitlements demo --set=projects=5       # override one
php artisan pm:entitlements demo --unset=billed_users   # no limit
```

When a limit is reached, the web app shows a dialog naming it (with an upgrade link if `PM_BILLING_URL` is set), and the API and MCP return 403 with `"error": "plan_limit"`. Everyone in the workspace sees current use at **Settings → Usage**.

### Environment

| Variable | Default | Purpose |
|---|---|---|
| `DB_*` | mysql / `pm_monkey` | Database connection. Tests use `pm_monkey_test` (see `phpunit.xml`). |
| `APP_URL` | `http://localhost` | Base URL used in links and printed endpoints. Set it to where you serve the app. |
| `MAIL_MAILER` | `log` | Invitations are written to `storage/logs/laravel.log`. Set SMTP to really send them. |
| `PM_EDITION` | `self_hosted` | `self_hosted` (one workspace, sign-up by invitation after the first person) or `cloud` (anyone signs up, many workspaces, free plan limits). See [Editions](#editions). |
| `PM_API_RATE_LIMIT` | `120` | Requests per minute per agent (REST and MCP over HTTP), when the workspace has no `api_per_minute` limit. |
| `PM_IDEMPOTENCY_TTL_HOURS` | `24` | How long a response is kept for `Idempotency-Key` replay. |
| `PM_INVITE_RATE_LIMIT` | `30` | Invitation emails per hour per user (web and API). |
| `PM_MCP_TOKEN` | — | Token the stdio MCP server acts as. Usually set in the MCP client's config, not `.env`. |
| `PM_MCP_REDIRECT_DOMAINS` | `localhost,127.0.0.1,claude.ai,claude.com` | Hosts an MCP client may register as its OAuth callback (comma list; `https` unless a scheme is given; `localhost` and `127.0.0.1` also allow `http://localhost:<port>`). A trust boundary (see [MCP server](#mcp-server)): keep only the callbacks you use. Anything else is refused at registration. |
| `PM_MCP_REDIRECT_SCHEMES` | `cursor,vscode` | App schemes (`cursor://…`) allowed as OAuth callbacks. `javascript`, `data`, `file` and `vbscript` are always refused. Empty allows none; keep only the apps you use. |
| `PM_AI_PROVIDER` | — | `anthropic`, `openai`, `google` or `openai_compatible` (Ollama, company gateways). Set, it makes **Settings → AI** read-only; empty, Owners and Admins set the provider there and the key is stored encrypted. |
| `PM_AI_MODEL`, `PM_AI_ENDPOINT`, `PM_AI_KEY` | — | Model, endpoint (required for `openai_compatible`; `https://`, or `http://localhost…`) and key for `PM_AI_PROVIDER`. The key is never shown in the app or returned by any API. |
| `PM_LINK_LOCK_TIMEOUT` | `5` | Seconds a change to links or dates waits for another link change in the same workspace before it answers "try again" (409). |
| `PM_SHIFT_LIMIT` | `500` | Most issues one due-date change may shift through `precedes` links. Over it, nothing is shifted (422 when you asked for the shift). |
| `PM_CLONE_LIMIT` | `100` | Most sub-tasks, and separately most links, one clone copies. Over it the clone is refused and nothing is saved. |
| `PM_BILLING_URL` | — | Upgrade link in the plan-limit dialog, and where Owners' **Billing** link goes. Leave empty on self-hosted. |
| `PM_BILLING_LINK_SECRET` | — | Signs the Billing link's 5-minute token. The link is hidden unless this and `PM_BILLING_URL` are set. The token rides in the URL and its claims are readable: the billing side should accept each nonce once and keep the query string out of its logs. |
| `PM_SYSTEM_EVENTS_URL`, `PM_SYSTEM_EVENTS_SECRET` | — | Where Cloud sends signed system events (members, projects, workspace changes) for Platform management; retried for 24 hours. Delivery is at least once, so the receiver should dedupe by `X-Pm-Event-Id`. Both empty: nothing is recorded. |
| `PM_INTERNAL_API_TOKEN`, `PM_INTERNAL_API_TOKEN_NEXT` | — | Service token(s) for the internal admin API (`/internal/v1`) Platform management calls on Cloud. Empty: the API is off and every path answers 404. `_NEXT` is accepted too, so you can rotate: set the new token as `_NEXT`, switch Platform management to it, then move it to `PM_INTERNAL_API_TOKEN`. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | — | Google sign-in. Leave empty to hide the button. See below. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | — | GitHub sign-in. Leave empty to hide the button. See below. |

## Google and GitHub sign-in

Both are optional. A provider's button appears only once its client id and secret are set; leave them empty to hide it.

**Google**
1. In the [Google Cloud console](https://console.cloud.google.com/apis/credentials), create an OAuth client ID of type *Web application*.
2. Add `APP_URL/auth/google/callback` (for example `http://localhost:8000/auth/google/callback`) as an authorised redirect URI.
3. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`.

**GitHub**
1. In GitHub, go to *Settings → Developer settings → OAuth Apps → New OAuth App*.
2. Set the callback URL to `APP_URL/auth/github/callback`.
3. Set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in `.env`.

How it behaves:
- A provider account links to an existing user only through a **verified** email that matches. For GitHub that means the primary verified email.
- New people follow the usual sign-up rules: on Cloud they get their own workspace; on self-hosted they need an invitation, unless they're the first person to sign up.
- Two-factor authentication still applies after a provider sign-in.
- Workspace Owners and Admins can limit which sign-in methods may enter, under *Workspace settings → Security*.
- Someone who signed up with a provider has no password until they set one with *Forgot your password?*.
- Nobody can unlink their last way to sign in.
- A provider links to an existing account only when that account's email is verified; otherwise sign in with the password, verify the email, then link it from the profile.
- If one of your workspaces doesn't allow the method you signed in with, you can't link a provider or change your email in that session.

## Running

```bash
composer run dev          # app server, queue worker, Vite with hot reload, and Reverb when configured
```

Or separately: `php artisan serve` and `npm run dev`.

### Live updates (Reverb)

Boards, backlogs, issue lists, issue pages and the inbox bell update while you look at them: a card someone moves slides to its new place, new comments and reactions appear, and the bell count goes up. Text you are typing is never replaced and a card you are dragging is never moved. Without Reverb, pages check for changes every 30 seconds instead.

To turn it on, set `BROADCAST_CONNECTION=reverb` and give `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` random values in `.env` (the `VITE_REVERB_*` lines copy them for the browser; run `npm run build` again after changing them). `composer run dev` then also starts `php artisan reverb:start`. Changes are sent through the queue, so the queue worker must run too.

In production, Reverb and a queue worker each need their own long-running process. Point `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` at the address browsers reach (usually your domain on 443 over `https`, with your web server proxying WebSocket traffic to Reverb on port 8080). Reverb accepts sockets only from the `APP_URL` host; if the app is served from other hosts too, list them in `REVERB_ALLOWED_ORIGINS` (comma separated, `*.example.com` wildcards). A Supervisor example:

```ini
[program:pm-reverb]
command=php /var/www/pm/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/pm-reverb.log

[program:pm-queue]
command=php /var/www/pm/artisan queue:work --tries=3
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/pm-queue.log
```

For production, run `npm run build` and serve `public/` with any PHP host over HTTPS. Then:
- Set `APP_ENV=production` and `APP_DEBUG=false`. With debug on, API and MCP errors include internal details.
- Set `SESSION_SECURE_COOKIE=true`.
- For live updates, run Reverb and a queue worker as their own processes (see Live updates above).
- Configure real SMTP so verification and invitation emails arrive.
- Run the scheduler (`php artisan schedule:work`, or cron with `schedule:run`). It runs `issues:purge`, `workspaces:purge`, `approvals:expire`, `notifications:agent-summary`, `system-events:activity`, `system-events:pending` and `support-sessions:end` (see `routes/console.php`).
- On `cloud`, registration is open to anyone. On `self_hosted`, only the first person and invited people can register.
- Leave `PM_INTERNAL_API_TOKEN` and `PM_INTERNAL_API_TOKEN_NEXT` empty unless Platform management calls this install; when set, use long random values (`openssl rand -hex 32`), serve `/internal/v1` over HTTPS only, and never log or commit them.

The app is at <http://localhost:8000>, and the API docs are at <http://localhost:8000/docs/api> (OpenAPI JSON at `/docs/api.json`).

Keyboard shortcuts: **c** creates an issue (board, backlog and list), **/** focuses search, **Esc** closes dialogs. The sun/moon button toggles light, dark and system themes.

### Deleting workspaces

An Owner can delete a workspace at **Settings → General**. It locks at once and goes for good after 7 days, when `workspaces:purge` runs; until then an Owner can restore it from the locked page.

### Deleted issues

Deleting an issue or comment (web, API or MCP) is a soft delete: it disappears everywhere but can be restored from the web app. `php artisan issues:purge` removes anything deleted more than 30 days ago; it is scheduled daily, so run the scheduler in production (`php artisan schedule:work`, or cron with `schedule:run`). The scheduler also runs `links:reconcile-duplicates` daily, which adds the `duplicates` link to a Close as Duplicate whose link failed to save.

## Tests and lint

```bash
php artisan test          # Pest: Feature + Integration suites (MySQL test DB)
vendor/bin/pint           # PHP code style
npm run build             # TypeScript check + production build
```

`tests/Integration` spawns the real stdio MCP server, so it uses `DatabaseTruncation` instead of transactions.

## Statuses, resolutions and edit conflicts

- **Statuses** belong to the workspace and every project's columns map to them. Workspace Owners and Admins rename, recolour, merge and delete statuses at **Settings → Statuses**; a status's category can't change. Project Admins attach or create statuses in a column from the project's settings, and anyone who can edit an issue changes its status from the menu on the issue page.
- **Resolution:** an issue in a Done-category status has a resolution: Done, Won't do, Duplicate (with the original's key) or Cannot reproduce. Moving into Done sets it (Done unless you pick another) and moving out clears it. Only Done counts as completed work in sprint progress.
- **Edit conflicts:** issues and comments carry a `version`. A save based on an old version is refused with the changes made meanwhile; on the web you can reload or save yours over theirs. REST and MCP edits must send the `version` they read (see REST API).
- **History:** status changes, sprint scope changes and estimate changes are recorded with who made them (and the agent and `via`, when there was one).

## Links, dates, cloning and Focus

- **Links:** link two issues from the issue's links section, the REST API or MCP. Types: blocks, precedes, relates to, duplicates and clones (reverse names such as "is blocked by" work too). Links can cross projects in the workspace, and blocks and precedes can't form a loop. An issue shows a **Blocked** badge (lists, board, My issues) while an issue that blocks it, and that you can see, isn't done. Closing an issue as Duplicate links it to its original.
- **Warning before starting:** moving a blocked issue into an In Progress status, or one that follows an unresolved issue, asks you to confirm first (web dialog; REST and MCP need `confirm: true`).
- **Visibility:** links are shown only for issues you can access. A hidden issue is never listed, counted or hinted at, including in warnings, activity and the Blocked badge.
- **Start dates and shifting:** issues have an optional start date (not after the due date). When a due date moves later, issues it `precedes` that would now start too early can move by the same gap, keeping their duration. Only issues you can edit move, resolved ones never do, and each moved issue gets its own history entry. **Profile → When a due date moves later** sets ask every time (the default: the date is saved and you choose in a dialog), always, or never. Shifting more than `PM_SHIFT_LIMIT` issues is refused. Undoing a due date doesn't undo the shifts.
- **Cloning:** **More actions → Clone** (REST `POST /api/v1/issues/{key}/clone`, MCP `clone_issue`) copies title, description, type, labels and story points into a new unassigned To Do issue with no sprint, epic or dates, linked "clones" the original. Optionally copy its sub-tasks and its blocks, precedes and relates to links (only to issues you can see). Comments and history aren't copied, and each option stops at `PM_CLONE_LIMIT`.
- **Focus:** the dashboard's My issues groups your open issues by what needs you: Waiting on you (approvals), Blocking others, Urgent or overdue, Due in the next 3 days, In progress, To do. Switch to grouping by status, priority, project or sprint from the same menu; the choice is remembered. Grouping applies to the issues loaded, so very long lists can be cut off.

## Watching, inbox, mentions and reactions

- **Watching:** the **Watch** button on an issue shows the watcher count. You watch what you create, are assigned or comment on (turn this off under *Profile*); project Admins can add or remove other members. The issue list has a *Watching* filter, and the dashboard lists what you watch. REST `POST`/`DELETE /api/v1/issues/{key}/watchers`, MCP `watch_issue` / `unwatch_issue`; an agent watching counts as its owner watching.
- **Inbox:** **Inbox** in the sidebar (with an unread badge) lists changes to issues you watch: comments, status and assignee changes, resolve and reopen. You are never notified of your own changes, quick changes by one person join one item (`PM_NOTIFICATION_BATCH_MINUTES`, default 5), and an agent's changes reach its owner as one daily summary (`notifications:agent-summary`, 00:10 server time). Approval requests from your agents arrive at once. Opening an item marks it read. Agents read the same stream with `get_updates` (`GET /api/v1/me/updates`).
- **Mentions:** write `@` and a member's email in a comment or description (`@sam@example.com`; the editor suggests members). They are told at once and start watching. A non-member is saved as plain text and the author is warned (Admins get an invite prompt); agents can't be mentioned, and an agent may send `PM_AGENT_MENTION_LIMIT` mentions an hour. Mentions inside code or links count for nothing. An assigned issue where you were mentioned and haven't answered shows under *Waiting on you*. Issue keys in text (`WEB-12`) link, with a hover card, only for readers who can see that issue.
- **Reactions:** react to a comment with one of 👍 ✅ 👀 🎉 ❤️ 😄 (REST `POST`/`DELETE /api/v1/comments/{id}/reactions`, MCP `add_reaction` / `remove_reaction`). They don't notify anyone.

## Internal admin API, suspension and system events

For Platform management on Cloud; self-hosted installs leave all of it off.

- **Internal admin API** (`/internal/v1`, bearer `PM_INTERNAL_API_TOKEN` or `_NEXT`): list and show workspaces, usage, plan entitlements and overrides, suspend/unsuspend, archive/restore and support sessions. Writes need an `Idempotency-Key`. Schemas are in `docs/contract/admin-api/`.
- **Suspended and archived workspaces** are read-only on web, REST and MCP (403 `workspace_suspended` / `workspace_archived`); scheduled upkeep keeps running. An Owner can restore an archived workspace.
- **Support sessions:** staff open a read-only, time-boxed (1-60 minutes) session through a single-use link; they never write, and a banner shows while one is open. `support-sessions:end` runs every minute.
- **System events:** members, projects and workspace changes are recorded in an outbox and POSTed, signed, to `PM_SYSTEM_EVENTS_URL` (see the env table; schemas in `docs/contract/events/`). `system-events:activity` runs daily at 00:20 and `system-events:pending` every 10 minutes to retry what did not arrive.
- **Billing link:** with `PM_BILLING_URL` and `PM_BILLING_LINK_SECRET` set, Owners get a **Billing** link that redirects with a signed 5-minute token (`docs/contract/billing-token.json`). Banners tell Owners about a trial ending, a past-due or canceling plan, and everyone about going over a limit.

## Agents

Create an agent at **sidebar → Agents** (`/demo/settings/agents`, under your workspace's address). Each agent has one token:

- **One workspace:** an agent belongs to the workspace it was made in and only sees that workspace. Make one per workspace if you need several.
- **Level:** Read, Read and write, or Read, write and delete. An agent never does more than you can (a Viewer's agent is read only). Only the Delete level can delete anything, including comments, labels, sprints and projects, and project settings, members and columns are people-only.
- **Existing tokens:** tokens made before agents existed became agents at their old level. A migrated write token lost delete until its owner picks "Read, write and delete" on the agent's edit page.
- **All my projects** or a chosen subset. A scoped agent can't see or create anything outside its projects.
- **Expiry:** 30 days, 90 days (the default), 1 year or never. Expired tokens stop working on REST and both MCP transports.
- The token is shown once. The page lists each agent's level, projects, status, **last used** time and expiry, and lets you **edit**, **pause**, **resume**, make a **new token** or **revoke** it. Workspace Owners and Admins also see everyone's agents and can pause, resume or revoke them.
- Owners and Admins can turn agents off for the whole workspace in Settings → Security; every agent call then gets `403 agents_disabled`.

### Approvals

An agent's move into a Done-category status doesn't happen at once. It opens an approval request and the agent is told `pending_approval` (REST 202). The agent's owner, or an Admin of the project who could make the move, decides on the dashboard under **Pending approvals** or in the **AI box** on the issue. If the issue changed since the request, approving needs a second confirm. A request goes stale if the issue is deleted, the status is no longer in the project, or the agent is revoked, and expires after 7 days. Agents learn the outcome with `get_updates`.

### Settings → AI

Workspace Owners and Admins set the AI provider (Anthropic, OpenAI, Google, or an OpenAI-compatible endpoint such as Ollama or a company gateway), model, key and a monthly token cap at **Settings → AI**. The key is stored encrypted and is never shown or returned. Changing the provider or endpoint needs a new key. Mode C sends issue content to the chosen provider; there is a workspace switch here and one per project.

To configure it on the server instead, set `PM_AI_PROVIDER`, `PM_AI_MODEL`, `PM_AI_KEY` and, for `openai_compatible`, `PM_AI_ENDPOINT`. The page is then read-only ("Configured by your server"); only the token cap stays editable. If the variables are incomplete or unusable (no key, no endpoint, a refused endpoint), they are ignored and the page says so, naming the variables and never their values. Endpoints must be `https://` (or `http://localhost`) and may not point at private or reserved addresses. The same check runs on every request to the provider: the host's DNS answers must all be public (private, loopback, link-local, carrier-grade NAT, multicast, cloud metadata and other reserved ranges, IPv4 or IPv6, are refused), the request connects to the address that was checked, and redirects aren't followed.

Every REST and MCP call uses the token as `Authorization: Bearer <token>`.

### Untrusted text

Agent instructions, issue descriptions and comments are untrusted input to agents. The server enforces access whatever any text says, in this order: your role in the project, then the agent's level, then its project list. Project settings, members and columns are people-only, a move to Done needs a person's approval, and deletes need the Delete level. Every agent action is recorded with the agent's label and `via`.

Residual risk: an agent with legitimate access to several projects can be talked into copying data between them. Scope each agent to the projects it needs.

## REST API

The base URL is `/api/v1`, documented at `/docs/api`. Highlights:

- Issues are addressed by key everywhere: `GET /api/v1/issues/WEB-12`.
- Fields take names, not ids: `status` is a status name (or a column name when that column has one status), `assignee` an email, `labels` label names, `epic`/`parent` issue keys, `sprint` a sprint name. Wrong values return 422 with the valid options listed.
- Destructive calls need `"confirm": true`: deleting a project or issue, and completing a sprint.
- **Idempotency:** send `Idempotency-Key: <uuid>` on any POST. A retry with the same key and body returns the original response (header `Idempotent-Replayed: true`). The same key with a different body gets 422.
- **Bulk:** `POST /api/v1/projects/WEB/issues/bulk` and `PATCH /api/v1/issues/bulk`, up to 50 items, all or nothing.
- **Edit conflicts (breaking change in step 1):** every issue and comment edit (`PATCH /issues/{key}`, each item of `PATCH /issues/bulk`, `PATCH /comments/{id}`, MCP `update_issue`, `bulk_update_issues`, `update_comment`) must send the `version` it read; issues and comments return it, and `fields` never drops it. Without it the call is a 422. If the issue or comment changed since, nothing is saved and you get 409 `{"error": "conflict", "message": "WEB-12 changed since version 3. Read it again and resend.", "current_version": 5, "changes": [{"field", "from", "to", "by", "agent", "via", "at"}], "issue": {…}}` (`changes` covers the newest 200 changes; `"changes_truncated": true` says older ones were left out) (a stale bulk item stops the whole call and the 409 lists every stale key under `conflicts`). Moves (`POST /issues/{key}/move`, `/rank`) need no version. Existing scripts must read the issue first and send its `version`.
- **Rate limit:** per token, the workspace's `api_per_minute` and `api_per_day` limits (or `PM_API_RATE_LIMIT` per minute when unset); a 429 says when to retry.

```bash
TOKEN=...   # from the seed output or /demo/settings/agents
curl -s http://localhost:8000/api/v1/me/issues -H "Authorization: Bearer $TOKEN"
curl -s -X POST http://localhost:8000/api/v1/projects/WEB/issues \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"title":"Fix footer on iOS","type":"bug","priority":"high","status":"To Do","labels":["frontend"]}'
```

## MCP server

The same server (`App\Mcp\Servers\PmServer`, 38 tools plus `issue://{key}` and `project://{key}` resources) is available over two transports. Both act as one of your agents, so that agent's level and project scope apply.

| Transport | How it runs | Auth |
|---|---|---|
| HTTP | `POST http://localhost:8000/mcp` (the app must be running) | `Authorization: Bearer <token>` header |
| stdio | `php artisan mcp:start pm` (the client starts it) | `PM_MCP_TOKEN=<token>` environment variable |

**OAuth (HTTP only).** Run `php artisan passport:keys` once per install (see Setup) before the first client connects; without keys OAuth refuses to start. A client that speaks MCP OAuth (Claude Desktop's and claude.ai's custom connectors, Cursor, VS Code, the MCP Inspector) needs no token: add `http://localhost:8000/mcp` as the server URL (in Cursor or VS Code, as a remote HTTP server with no headers). It registers itself, opens a consent screen in the browser, and you pick which of your agents (in your current workspace) it acts as, or create "{First name}'s {client} agent" at Read and write. The consent screen says where the client will send you back and that it registered itself (PmOps doesn't verify apps), and applies the workspace's rules (sign-in methods, required two-factor, agents turned on). When a workspace requires two-factor, creating an agent needs it on; agents that already exist keep working. Each connection shows under that agent in Settings → Agents; pausing or revoking the agent stops it. A **new token** for the agent does not disconnect its OAuth clients: revoke the agent to cut them off (disconnecting one client is planned).

The redirect allowlist is a trust boundary: any app can register itself, and the allowlist decides where a consent code may be sent. The defaults (`claude.ai`, `claude.com`, `localhost`, and the `cursor`/`vscode` schemes) rely on those clients' own state checks to reject a code they didn't ask for. On a self-hosted install, set `PM_MCP_REDIRECT_DOMAINS` and `PM_MCP_REDIRECT_SCHEMES` to only the callbacks your people actually use, for example `PM_MCP_REDIRECT_DOMAINS=claude.ai` and `PM_MCP_REDIRECT_SCHEMES=` (empty) if everyone connects from claude.ai. OAuth tokens work on `/mcp` only, not the REST API. In production, set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` instead of the key files if storage isn't persistent.

Use the **absolute path** to `php` (`which php`, e.g. `/opt/homebrew/bin/php`) and to `artisan`, because desktop apps don't inherit your shell `PATH`. The stdio server needs MySQL reachable, but not `php artisan serve`.

### Claude Code

stdio (Claude Code starts the server itself):

```bash
claude mcp add pm-ops --env PM_MCP_TOKEN=<token> -- /opt/homebrew/bin/php /path/to/PM-Ops/artisan mcp:start pm
```

HTTP (the app must be running):

```bash
claude mcp add --transport http pm-ops http://localhost:8000/mcp --header "Authorization: Bearer <token>"
```

Add `--scope user` to make it available in every project, or `--scope project` to write a shareable `.mcp.json`. For a shared `.mcp.json`, reference an environment variable instead of pasting the token:

```json
{
  "mcpServers": {
    "pm-ops": {
      "type": "http",
      "url": "http://localhost:8000/mcp",
      "headers": { "Authorization": "Bearer ${PM_OPS_TOKEN}" }
    }
  }
}
```

Check with `claude mcp list`, or `/mcp` inside Claude Code.

### Claude Desktop

Edit `claude_desktop_config.json` (macOS: `~/Library/Application Support/Claude/`, Windows: `%APPDATA%\Claude\`), then fully quit and reopen Claude Desktop.

stdio (recommended):

```json
{
  "mcpServers": {
    "pm-ops": {
      "command": "/opt/homebrew/bin/php",
      "args": ["/path/to/PM-Ops/artisan", "mcp:start", "pm"],
      "env": { "PM_MCP_TOKEN": "<token>" }
    }
  }
}
```

HTTP, through the `mcp-remote` bridge (a third-party npm package, needs Node), which forwards stdio to `/mcp` with your header:

```json
{
  "mcpServers": {
    "pm-ops-http": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "http://localhost:8000/mcp", "--header", "Authorization:${PM_AUTH}"],
      "env": { "PM_AUTH": "Bearer <token>" }
    }
  }
}
```

(The header is written without a space after the colon and the value comes from `env`. This avoids an argument-quoting problem in some clients.)

### Debugging

```bash
php artisan mcp:inspector pm        # opens the MCP Inspector against the stdio server
```

If the stdio server exits at once with "PM_MCP_TOKEN is missing or invalid", the token is wrong or revoked. Create a new agent at `/{workspace}/settings/agents`. Raw HTTP check:

```bash
curl -s -X POST http://localhost:8000/mcp -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json, text/event-stream" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"project_summary","arguments":{"project":"WEB"}}}'
```

## Architecture

Laravel 13 with Inertia, React, TypeScript and Tailwind (Breeze). Sanctum handles tokens, laravel/mcp the MCP server, Scramble the OpenAPI docs, dnd-kit drag and drop, and Pest the tests.

All business logic lives in `app/Services`. Web controllers (`app/Http/Controllers/Web`), API controllers (`app/Http/Controllers/Api/V1`) and MCP tools (`app/Mcp/Tools`) are thin layers over the same services, Form Requests, Policies and API Resources. Conventions are in [CLAUDE.md](CLAUDE.md).

## Known gaps

Out of scope by design: a workflows engine, custom fields, plugins, time tracking, SSO, email notifications and presence (live updates refresh pages; there are no shared cursors or typing indicators).

Also not done:
- The board and backlog load at most 500 and 1000 issues, with a notice when they're cut off.
- There's no in-app list of keyboard shortcuts.
- MCP tools don't take an idempotency key; use the REST API when you need replay safety.

## License

This project is licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (`AGPL-3.0-only`). Copyright (C) 2026 ogmnt.io.

- You can use, self-host and change it freely.
- If you change the code and let other people use your version over a network, you must offer those users the source code of your version under the same licence.
- Contributions need a signed [Contributor License Agreement](CLA.md) before they can be merged. A bot asks for it on your first pull request; see [CONTRIBUTING.md](CONTRIBUTING.md).
