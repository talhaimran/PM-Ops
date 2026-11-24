# Step 3: watchers, notifications, @mentions, reactions, live updates, event bus, system events, internal admin API. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build spec step 3: watchers and in-app notifications (7.10), @mentions, issue-key links, reactions and live updates (7.11), the internal event bus, system events, internal admin API, billing link and billing banners (section 9), and feed unanswered @mentions into My issues "Waiting on you" (5.1).

**Architecture:** The event bus is Laravel's event dispatcher: `ActivityLogger::log` and `AuditLogger::log` already see every issue change and every admin event, so each dispatches one event after commit (`ActivityRecorded`, `AuditRecorded`). Listeners turn them into notifications (`NotificationService`), live-update broadcasts (`LiveUpdates`) and system events (`SystemEventService`); step 7's webhooks will listen to the same two events. Broadcasts carry no issue content: they tell the page "project P / issue K changed" and the page re-reads through Inertia partial reloads, which re-check access, so the same code serves the 30-second polling fallback. Outbound system events use an outbox table and a queued, signed, retried sender that step 7's webhooks will reuse.

**Tech Stack:** Laravel 13, PHP 8.3+, MySQL 8, Pest, Inertia + React + TS, laravel/mcp, Sanctum/OAuth agents. New (pending D1, D2): `laravel/reverb`, `laravel-echo`, `pusher-js`, and dev-only `justinrainbow/json-schema`.

**Spec:** `docs/specs/pmops-spec.md` 7.10, 7.11 (all but edit conflicts, done in step 1), 9, 5.1 (live updates, Focus "Waiting on you", agents in the UI), 3 (billing link). Contract detail also in `~/Documents/PmOps/platform-management-spec.md` section 4 (outside the repo; it matches spec 9 and adds: suspend takes `reason`; archived workspaces are restored by an Owner in the core).

## Global Constraints

- CLAUDE.md is in force: logic in `app/Services`, thin web/API/MCP, Form Requests, Policies, API Resources, Pest on MySQL, the per-PR checklist, a `WorkspaceIsolationTest` case for every new route, endpoint, channel and MCP tool, AGENTS.md tool table updated when tools change.
- Ponytail (full): smallest complete change; reuse existing services; known limits as `shortcut: <limit>, <when to upgrade>`.
- Nobody edits `docs/specs/pmops-spec.md`. Internal identifiers keep their names (`pm_monkey`, `pm`, `PM_*`, `pm.via`, `Pm*`).
- Email notifications are out of scope. Voting and threaded replies are deferred. No presence or co-editing.
- Never notify people about their own changes. Your own agent's changes go into a daily summary, not immediate notifications. @mentions and approval requests are never batched.
- Reactions: fixed set 👍 ✅ 👀 🎉 ❤️ 😄; hover shows who; no notifications; doesn't change last-updated (or `version`).
- People can't @mention agents. Agent mentions show as "{Owner}'s agent mentioned you" and are rate-limited per agent.
- Live updates only reach users who can see the project in that workspace; they never take focus, never move a card being dragged, never replace text being edited. Polling every 30 seconds when Reverb isn't connected.
- Everything shown (inbox, watchers, mentions, links in text) filters through `accessibleProjects()` / `Issue::visibleTo`; hidden issues are never counted, listed or hinted.
- Internal admin API, system events and billing link are enabled only when their env vars are set: `PM_INTERNAL_API_TOKEN` (+ `PM_INTERNAL_API_TOKEN_NEXT`), `PM_SYSTEM_EVENTS_URL` + `PM_SYSTEM_EVENTS_SECRET`, `PM_BILLING_URL` + `PM_BILLING_LINK_SECRET`. No secret is ever logged, returned or put in an audit row.
- The contract (admin API requests/responses, system events, billing token) is written as JSON Schemas in `docs/contract/`, and tests validate against them. The same files go to the Platform management repo.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; PR bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Decisions for the owner

| # | Topic | Recommendation |
|---|---|---|
| 3-D1 | Reverb packages | Install `laravel/reverb` (composer, MIT; pulls MIT ReactPHP/Ratchet/Pusher server packages) and `laravel-echo` + `pusher-js` (npm, MIT), via `php artisan install:broadcasting --reverb` (adds `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, an Echo bootstrap). I'll list every package and licence from the lock diff before committing. |
| 3-D2 | Contract tests | Add `justinrainbow/json-schema` (MIT) as **require-dev only**, to validate responses and events against `docs/contract/*.json`. Alternative: hand-written key/type checks (weaker; schemas can drift). |
| 3-D3 | @mention syntax | Stored in text as `@` + email (`@alex@example.com`), which agents can write and the API already accepts as a person; rendered as a highlighted "@Alex Kim". The web composer's `@` list inserts it. Alternative: a hidden id form like `@[Alex](user:12)` (unreadable for agents). |
| 3-D4 | Support sessions (staff impersonation) | **Read-only**: staff sign in as the target user through the one-time URL, see what they see, and can't write anything (same check as a suspended workspace), with a banner and audit rows. Alternative: full act-as-user except security/account/agents/billing (more useful, much riskier). |
| 3-D5 | Delivery and scans | One PR per task, merged before the next; branches `step-3/<n>-<slug>`. You run claude-security "scan changes" on **PR 2** (notifications: cross-project leaks), **PR 4** (live updates: channel auth) and **PR 5** (internal admin API and support sessions). |

## Rulings (manager, within the spec; change any you disagree with)

| # | Topic | Ruling |
|---|---|---|
| 3-R1 | Event bus | `ActivityRecorded(Activity)` and `AuditRecorded(AuditLog)` are dispatched from the two loggers with `afterCommit`, so a rolled-back change emits nothing. Reactions (not in the activity log, 7.11) dispatch `ReactionChanged`. No other bus. |
| 3-R2 | Auto-watch | Reporter on create, assignee on assign, commenters, @mentioned people; source `auto`. `users.auto_watch` (default true) turns it off per user; manual watching still works. The migration backfills reporter, assignee and commenters of every non-deleted issue for users with access. |
| 3-R3 | Who adds watchers | Members and Admins add anyone who can see the issue; Viewers only themselves; anyone removes themselves; removing another person needs project Admin. An agent's `watch_issue` adds its owner (spec: "an agent watching counts as its owner watching"). `watch_issue`/`unwatch_issue` need Write level (CLAUDE.md: read-only tokens never write). |
| 3-R4 | Who is notified | Watchers who can still see the issue, minus the actor. When an agent acts, its owner is the actor for this rule and gets the daily summary instead. Notifications for issues that later become hidden or deleted are left out of the inbox when read. |
| 3-R5 | Triggers now | Comment added, status change, assignee change, resolve/reopen, @mention, approval request (to the agent's owner, immediately). Attachments (step 6) and agent runs (step B) join in their steps. Label, priority, title and other field edits don't notify. |
| 3-R6 | Batching | A change joins the recipient's newest **unread** notification for the same issue and the same actor (person or agent) if it was created within **5 minutes** (`pm.notification_batch_minutes`); the row keeps a list of changes and a count. Mentions and approvals always get their own row. |
| 3-R7 | Daily summary | `notifications:agent-summary`, scheduled daily at 00:10 server time, covering the previous day: one notification per owner, "Your agent updated 14 issues yesterday" (or "Your agents…"), with the issue keys (visible ones only). Nothing when zero. `shortcut: server-time day, upgrade when users get a time zone`. |
| 3-R8 | `get_updates` | Keeps its cursor and adds the owner's notifications (watched-issue changes and mentions, but not the daily summary) to the approval outcomes, one stream oldest first; optional `since` (ISO time) when no cursor. REST `GET /me/updates` mirrors it. It doesn't mark anything read. |
| 3-R9 | Mentions | Parsed from comments and descriptions on create and update; only newly added mentions create rows and notifications. A mentioned email that isn't a member of the issue's project gets no row and no notification; the save succeeds with a warning: web shows Admins "Invite Sam to this project?" (existing invitation flow) and others "Sam can't see this issue."; REST/MCP get `warnings: [{code: "mention_not_member", …}]`. |
| 3-R10 | Unanswered mention | A mention is answered once the mentioned person comments on that issue after it. Unanswered mentions on open issues put the issue in "Waiting on you" (Focus) and in the dashboard's "Waiting on you" count. |
| 3-R11 | Agent mention limit | 20 mentions per agent per hour (`pm.agent_mention_limit`). Over it, the text saves, the extra mentions notify nobody, and the response warns `mention_rate_limited`. (Warn, don't block.) |
| 3-R12 | Issue keys in text | Rendered Markdown links `KEY-123` to the issue page only when the key resolves to an issue the viewer can see (one query per render); the hover preview shows key, title and status from data attributes on the link. Unseen or unknown keys stay plain text. Done server-side in `PresentsIssue::markdown`, after Markdown, on text nodes only (never inside code or existing links). |
| 3-R13 | Reactions | On comments only. A person and each of their agents react separately; removing your own reaction is Write level with no `confirm`. Not in the activity log. REST `POST`/`DELETE /api/v1/comments/{id}/reactions`; MCP `add_reaction` / `remove_reaction`. |
| 3-R14 | Channels | Private channels `workspace.{w}.project.{p}` (board, list, issue page, comments, reactions) and `user.{u}` (bell). Channel auth checks workspace membership, then `accessibleProjects()`. Payloads hold ids and keys only, never titles, text or field values. Agent tokens can't subscribe. |
| 3-R15 | Live refresh and fallback | On an event, or every 30 s while Echo isn't connected (or Reverb isn't configured), the page does an Inertia partial reload of its own props (`preserveState`, `preserveScroll`). The board defers it while a drag is active; an open editor keeps its text (the step 1 conflict check covers its save). Cards move with Motion `layout` animations from `motion.ts`. |
| 3-R16 | Suspended | A suspended workspace is read-only for everyone on web, REST, MCP and stdio (sign-in, reading, export and switching still work), with a notice banner. Writes get `403 {"error":"workspace_suspended"}` (web: the notice). One check, reached by every write path. |
| 3-R17 | Archived | Members of an archived workspace see a "This workspace was archived after 12 months without activity" page; Owners get "Restore" (sends `workspace.updated`, audit `workspace.restored`), others "Ask an Owner to restore it". API/MCP get `403 workspace_archived`. Admin API `restore` does the same. |
| 3-R18 | Admin API actors | Writes may carry `"staff": {"name","email"}`; then the audit row is actor type `staff` with those details, else `system`. Idempotency: the existing `Idempotent` middleware, extended to PUT/DELETE and keyed `internal:{path}` for this API; `Idempotency-Key` is required on internal writes (400 without). Unknown workspace: 404 in the normal error shape. Disabled (no token): 404 for every path. Tokens compared with `hash_equals`. |
| 3-R19 | Support sessions | `support_sessions` table; the URL is single-use and valid 5 minutes; the session lasts `minutes` (1–60); the target must be a member; signing in through it skips 2FA (staff are verified by Platform management) but is read-only (3-D4); banner "Support session: {staff name} is viewing as {user}, ends at HH:MM"; audit `staff.support_session_started` and `staff.support_session_ended` in the workspace log (Owners and Admins see it there). |
| 3-R20 | Usage fields | Storage MB is 0 until step 6. API calls (30 days) come from a new `workspace_api_usage` (workspace_id, day, calls) counted in the per-agent limiter middleware with one upsert per request (`shortcut: one write per API call, move to a cache counter if it shows up in profiling`). Last activity = latest of members' `last_active_at`, issue activity and agents' `last_used_at`. |
| 3-R21 | System events | Sources: `workspace.created/updated` (name or slug changed)`/deleted`, `workspace.owner_changed` (owner added/removed), `member.added/removed/role_changed` (also when a project role change flips the person's billed status), `project.created/deleted`, `workspace.activity` (daily command, workspaces with activity that day). `project.archived` waits for step 5 and `storage.changed` for step 6 (nothing can change either yet). Nothing is written when `PM_SYSTEM_EVENTS_URL` is unset. |
| 3-R22 | Event delivery | Outbox `system_events` (uuid id, workspace_id nullable, type, payload JSON, attempts, last_status, last_error, delivered_at, timestamps), written by the `AuditRecorded` listener right after the change commits (`shortcut: a crash between commit and that write loses the event; Platform management's nightly reconciliation corrects it; move the write into the transaction if drift shows up`); a queued `DeliverSystemEvent` job POSTs JSON with headers `X-Pm-Event-Id`, `X-Pm-Timestamp`, `X-Pm-Signature: sha256=<hex HMAC of "{timestamp}.{body}">`; backoff 1, 5, 15, 60 min then hourly, `retryUntil` 24 h; 2xx marks delivered. No redirects followed; 10 s timeout. |
| 3-R23 | Billing link | "Billing" in workspace settings (Owners only, when `PM_BILLING_URL` and `PM_BILLING_LINK_SECRET` are set) redirects to `PM_BILLING_URL/session?token=…`; token = base64url(JSON claims) + "." + base64url(HMAC-SHA256); claims `workspace_id, user_id, email, name, role, iat, exp (iat+300), nonce` (32 random bytes, hex). Nonce single-use is checked by Platform management. |
| 3-R24 | Billing banners | Plan name on Usage and limits. Owners: "Trial: N days left" from `trial_ends_at`; `past_due` "Payment failed. Update your payment method." and `canceling` "Your plan is set to cancel." (no key carries a date: see spec gaps), each with the Billing link. `read_only` "This workspace is over its plan limits." shows to everyone (Billing link for Owners). Banner only: read-only enforcement after a downgrade stays with Platform management (spec section 4). |
| 3-R25 | Inbox | Bell with unread count in the sidebar (shared prop, one indexed count), `/{workspace}/notifications` (design system NotificationInbox): items name the issue, the change and who ("Alex's agent moved WEB-12 to In Review"); mark read, mark all read; clicking opens the issue and marks it read. Web only. No purge (`shortcut: notifications kept forever, add a 90-day purge when the table grows`). |

**Spec gaps to note (not blocking; no spec edit):** `billing_notice=canceling` has no date key, so the banner shows no date; `project.archived` and `storage.changed` can't fire until steps 5 and 6; notification triggers for attachments and agent runs arrive in steps 6 and B; `agent_run_id` on notifications arrives with step B.

## Execution (cost-lean, as steps 1 and 2)

| Task (PR) | Implementer | Task reviewer | Scan (owner) |
|---|---|---|---|
| 1 Event bus and watchers | Sonnet | Sonnet / medium (after-commit, backfill) | No |
| 2 Notifications and inbox | Sonnet | Sonnet / medium (visibility, batching) | Yes |
| 3 Mentions, key links, reactions | Sonnet | Sonnet / medium (rendered HTML, non-member mentions) | No |
| 4 Live updates (Reverb) | Opus / high | Sonnet / medium (channel auth, payloads) | Yes |
| 5 Internal admin API, suspend/archive, support sessions | Opus / high | Opus / medium | Yes |
| 6 System events, billing link, banners | Sonnet | Sonnet / medium (signing, secrets) | No |
| 7 Docs, seed, final review | Sonnet | One Opus / high whole-branch review, one Sonnet fix wave, a scoped Sonnet re-review | No |

At most 2 fix rounds per task, then I rule. I read every diff and run the checks myself. Browser checks are mine, in the `app` preview, light, dark and 375px, with probe data removed. Dev DB backed up with mysqldump before each migration. Ledger: `.superpowers/sdd/step-3/progress.md`.

## Review Focus

1. **A watcher or mentioned person who loses access to the project** (removed, project left, issue moved out of reach, issue deleted): no new notification, the inbox hides old ones, the channel refuses them, `get_updates` leaves them out. Tests in Tasks 2 and 4.
2. **An agent acting on an issue its owner watches**: the owner gets no immediate notification but the daily summary; other watchers see "Alex's agent …"; the agent's mention flood stops at the limit with a warning. Tests in Tasks 2 and 3.
3. **Text with `@` and issue keys inside code blocks, links, emails and HTML** (`` `@a@b.co` ``, `<a href=…>WEB-1</a>`, `<script>`): no mention from code, no nested links, no HTML injected by the key linker. Tests in Task 3.
4. **A write in a suspended workspace through every path** (web form, board drag, REST, MCP, stdio, bulk, approval decision, Undo, support session): refused with the notice; reads and export still work. Tests in Task 5.
5. **Replayed or forged admin calls and events**: wrong, missing or old token → 404/401 without detail; a repeated `Idempotency-Key` replays without a second audit row; a delivery that fails retries and a duplicate send keeps the same event id. Tests in Tasks 5 and 6.

---

### Task 1 (PR 1): Event bus and watchers

Branch `step-3/1-watchers`.

**Files:**
- Create: migration `…_create_issue_watchers.php` (`issue_watchers`: issue_id, user_id, source `auto|manual`, timestamps; unique (issue_id, user_id), FKs cascade, index user_id; `users.auto_watch` boolean default true; the 3-R2 backfill); `app/Events/ActivityRecorded.php`, `AuditRecorded.php`; `app/Models/IssueWatcher.php`; `app/Services/WatcherService.php`; `app/Mcp/Tools/WatchIssue.php`, `UnwatchIssue.php`; `app/Http/Controllers/Api/V1/WatcherController.php` (or methods on `IssueController`, as the codebase does); tests `tests/Feature/Watchers/WatcherTest.php`, `tests/Feature/Events/EventBusTest.php`, `tests/Integration/WatcherBackfillTest.php`
- Modify: `ActivityLogger::log`, `AuditLogger::log` (dispatch), `IssueService::create/update`, `CommentService::add` (auto-watch), `IssueService::filter` (`watching`), `DashboardService` ("Watching" section), `IssueResource` (`watchers_count`, `watching`), `IssueView.tsx` (Watch button, count, watcher list, add watcher), `ProfileUpdateRequest` + `Profile/Edit.tsx` (`auto_watch`), routes (web and `/api/v1`: `POST`/`DELETE /issues/{key}/watchers`), `PmServer::$tools`, AGENTS.md, `WorkspaceIsolationTest`

**Interfaces:**
- Produces: `WatcherService::watch(Issue $issue, User $user, User $actor, string $source = 'manual'): void`; `unwatch(Issue $issue, User $user, User $actor): void`; `autoWatch(Issue $issue, User $user): void` (respects `auto_watch`, ignores a user who can't see the issue); `watchers(Issue $issue): Collection<User>`; `isWatching(Issue, User): bool`. Events `ActivityRecorded::$activity`, `AuditRecorded::$log`.

- [ ] **Step 1: Failing tests.** `EventBusTest`: a change inside a transaction that rolls back dispatches nothing; a committed issue update dispatches one `ActivityRecorded` with its activity; an audited role change dispatches `AuditRecorded`. `WatcherTest`: reporter, assignee, commenter auto-watch; `auto_watch=false` stops it; a Member adds a teammate; a Viewer adds only themselves (403 for others); adding someone who can't see the issue is a 422 listing nothing hidden; an agent's `watch_issue` adds its owner; a read-only agent gets 403; `watching=true` filter on web list, REST and MCP `search_issues`; dashboard "Watching" lists visible issues only; watch count and list on the issue JSON. `WatcherBackfillTest`: backfill rows for reporter/assignee/commenters, none for deleted issues or users without access, re-run adds none.
- [ ] **Step 2: Run; see them fail.**
- [ ] **Step 3: Implement.** Dispatch with `DB::afterCommit` (or `ShouldDispatchAfterCommit` on the events).
- [ ] **Step 4: Suite; browser check** Watch button, count, list, add watcher; light, dark, 375px.
- [ ] **Step 5: Per-PR checklist and PR.**

### Task 2 (PR 2): Notifications, inbox, daily summary

Branch `step-3/2-notifications`.

**Files:**
- Create: migration `…_create_notifications.php` (`notifications`: id, workspace_id FK, user_id FK, issue_id nullable FK cascade, event string, actor_user_id nullable, agent_id nullable, via, changes JSON (list of `{action, from, to}`), count int default 1, read_at nullable, timestamps; indexes (user_id, workspace_id, read_at), (user_id, issue_id, created_at)); `app/Models/Notification.php` (named to avoid Laravel's `DatabaseNotification`; don't use Laravel's notification channel); `app/Services/NotificationService.php`; `app/Listeners/NotifyOnActivity.php`; `app/Console/Commands/SendAgentSummaries.php`; `app/Http/Controllers/NotificationController.php`; `resources/js/Pages/Notifications/Index.tsx`, `resources/js/Components/NotificationBell.tsx`; tests `tests/Feature/Notifications/NotificationTest.php`, `BatchingTest.php`, `AgentSummaryTest.php`, `tests/Feature/Security/NotificationSecurityTest.php`
- Modify: `ApprovalService::requestDone` (notify owner), `ApprovalService::updatesFor` / `GetUpdates` / `MeController` (3-R8), `HandleInertiaRequests` (`unread_notifications`), sidebar layout (bell), `routes/console.php` (schedule), routes (`GET /{workspace}/notifications`, `POST …/notifications/{id}/read`, `POST …/notifications/read-all`), AGENTS.md (`get_updates`), `WorkspaceIsolationTest`

**Interfaces:**
- Consumes: `ActivityRecorded`, `WatcherService::watchers`.
- Produces: `NotificationService::notify(User $to, Issue $issue, string $event, array $change, ?User $actor, ?Agent $agent, string $via, bool $batch = true): ?Notification`; `inbox(User $user, Workspace $w, int $perPage = 25): LengthAwarePaginator` (visible only); `markRead(User $user, int|array|string $ids): int` (`'all'` marks every one in the workspace); `unreadCount(User, Workspace): int`; `summary(Workspace $w, User $owner, Carbon $day): ?Notification`. Task 3 calls `notify(..., batch: false)` for mentions.

- [ ] **Step 1: Failing tests.** `NotificationTest`: each 3-R5 trigger notifies watchers and not the actor; label and priority edits don't; an agent's move notifies other watchers as "Alex's agent moved WEB-12 to In Review" and not Alex (3-R4); an agent's approval request notifies the owner immediately and not project Admins; mark read, mark all read, unread count; `get_updates` returns watched changes and approval outcomes in one stream after a cursor, and `since`. `BatchingTest`: three edits by one actor within 5 minutes → one row, count 3; a fourth after 6 minutes → new row; a read row isn't extended; another actor → separate row; approvals never batch. `AgentSummaryTest`: owner gets one summary for yesterday with visible keys only; none when zero; a re-run for the same day doesn't duplicate. `NotificationSecurityTest` (Review Focus 1): a removed member gets nothing new and the inbox hides old items; a deleted issue's items vanish; another workspace's notifications never show; marking another user's notification read is a 404.
- [ ] **Step 2: Run; see them fail.**
- [ ] **Step 3: Implement.** Batch under a lock on the recipient's newest unread row for the issue (`lockForUpdate` in a transaction) so two quick changes can't make two rows.
- [ ] **Step 4: Suite; browser check** bell count, inbox, mark read, empty state ("You're all caught up."); light, dark, 375px.
- [ ] **Step 5: Per-PR checklist and PR.** Owner runs the scan.

### Task 3 (PR 3): @mentions, issue-key links, reactions

Branch `step-3/3-mentions-reactions`.

**Files:**
- Create: migration `…_create_mentions_and_reactions.php` (`mentions`: issue_id FK cascade, comment_id nullable FK cascade, mentioned_user_id, by_user_id, agent_id nullable, via, created_at; index (mentioned_user_id, issue_id); `comment_reactions`: comment_id FK cascade, user_id, agent_id nullable, via, emoji, reactor string (`u{id}` or `a{id}`), created_at; unique (comment_id, emoji, reactor)); `app/Services/MentionService.php`, `ReactionService.php`; `app/Enums/Reaction.php`; `app/Mcp/Tools/AddReaction.php`, `RemoveReaction.php`; `resources/js/Components/MentionInput.tsx` (textarea with `@` list of project members), `Reactions.tsx`; tests `tests/Feature/Mentions/MentionTest.php`, `tests/Feature/Reactions/ReactionTest.php`, `tests/Feature/Security/RenderedTextTest.php`
- Modify: `CommentService::add/update`, `IssueService::create/update` (description mentions; `warnings`), `PresentsIssue::markdown` (mention highlight, 3-R12 key links), `DashboardService::withFocus` (3-R10), comment resources (reactions grouped by emoji with who), `IssueView.tsx` (composer, reactions), routes (web and `/api/v1` reactions; web `GET /{workspace}/projects/{key}/mentionable`), `PmServer::$tools`, AGENTS.md (mention syntax, reactions, warnings), `WorkspaceIsolationTest`

**Interfaces:**
- Consumes: `NotificationService::notify(batch: false)`, `WatcherService::autoWatch`.
- Produces: `MentionService::sync(Issue $issue, ?Comment $comment, ?string $old, string $new, User $actor): array` (returns warnings); `MentionService::unanswered(User $user, Collection $issueIds): Collection` (issue ids); `ReactionService::add(Comment $c, Reaction $emoji, User $actor): void`, `remove(...)`, `summary(Comment $c): array` (`[{emoji, count, people: [labels]}]`, agents as "{Owner}'s agent").

- [ ] **Step 1: Failing tests.** `MentionTest`: `@sam@example.com` in a comment creates one mention, notifies Sam immediately (unbatched) and auto-watches; editing to add `@alex@…` notifies only Alex; a non-member email saves with `mention_not_member` (REST, MCP) and no notification; an agent's mention reads "Alex's agent mentioned you"; the 21st agent mention in an hour warns `mention_rate_limited` and notifies nobody; Sam's issue sits in Focus "Waiting on you" until Sam comments; mentions in descriptions work the same. `ReactionTest`: add/remove each of the six; a seventh emoji is a 422 listing the six; a person and their agent react separately; a repeat add is idempotent; no notification, no `updated_at` or `version` change, no activity row; Viewer and read-only agent get 403; MCP and REST shapes. `RenderedTextTest` (Review Focus 3): mentions and keys inside inline code, fenced code and existing links are left alone; `<script>` stays stripped; a key of an unseen or other-workspace issue stays plain text with no title leaked; the hover data is escaped.
- [ ] **Step 2: Run; see them fail.**
- [ ] **Step 3: Implement.** Post-process the Markdown HTML with `DOMDocument` on text nodes only; never regex over raw HTML.
- [ ] **Step 4: Suite; browser check** `@` list, highlighted mention, non-member prompt for Admin and Member, key link hover, reactions with "who" on hover; light, dark, 375px.
- [ ] **Step 5: Per-PR checklist and PR.**

### Task 4 (PR 4): Live updates (Reverb) with polling fallback

Branch `step-3/4-live-updates`. Starts only after 3-D1 is approved; I confirm the lock diff's packages and licences with the owner before committing.

**Files:**
- Create (via `install:broadcasting --reverb`, then trimmed): `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, `resources/js/echo.ts`; `app/Events/ProjectChanged.php`, `NotificationsChanged.php` (`ShouldBroadcast`, after commit, payload ids/keys only); `app/Listeners/BroadcastChanges.php`; `resources/js/useLiveReload.ts` (subscribe or poll every 30 s, defer while dragging); tests `tests/Feature/Live/ChannelAuthTest.php`, `BroadcastTest.php`, `resources/js/useLiveReload.test.ts`
- Modify: `.env.example` (`BROADCAST_CONNECTION`, `REVERB_*`, `VITE_REVERB_*`), `composer run dev` / `php artisan dev` (start `reverb:start` when configured), Board, Backlog, project list, issue page and drawer, bell (`useLiveReload`), `IssueCard`/`BoardColumn` (Motion `layout`), `WorkspaceIsolationTest` (channels)

**Interfaces:**
- Consumes: `ActivityRecorded`, `ReactionChanged`, notification creation (Task 2).
- Produces: channel names (3-R14); `ProjectChanged(int $workspaceId, int $projectId, ?string $issueKey, string $kind)`; `useLiveReload(channel: string | null, only: string[], opts?: {paused?: () => boolean}): void`.

- [ ] **Step 1: Failing tests.** `ChannelAuthTest`: a project member is authorised; a member of another workspace, a removed member, a workspace Member not in the project (and an Admin before joining: read-only view is allowed, as `viewAllProjects` says) get the right answer; an agent token is refused; a guessed `user.{id}` channel of someone else is refused. `BroadcastTest`: an issue update broadcasts one `ProjectChanged` after commit with no title or text in the payload; a rollback broadcasts nothing; a reaction and a comment broadcast; a notification broadcasts `NotificationsChanged` to its user only. `useLiveReload.test.ts`: polls every 30 s without Echo, stops polling when connected, defers while `paused()` is true and reloads once after.
- [ ] **Step 2: Run; see them fail.**
- [ ] **Step 3: Implement.** Without `REVERB_APP_KEY`, the client never loads Echo and only polls.
- [ ] **Step 4: Suite; browser check** with Reverb running: two sessions (demo Owner and alex), a move in one slides the card in the other, a comment and reaction appear, the bell count rises, a drag in progress isn't disturbed, an open comment edit keeps its text; then with Reverb stopped, the 30 s poll picks changes up; light, dark, 375px; reduced motion: no animation.
- [ ] **Step 5: Per-PR checklist and PR.** README gets the Reverb process (production needs its own process; Supervisor example). Owner runs the scan.

### Task 5 (PR 5): Internal admin API, suspend and archive, support sessions

Branch `step-3/5-admin-api`.

**Files:**
- Create: migration `…_create_support_sessions_and_api_usage.php` (`support_sessions`: id, workspace_id FK, target_user_id FK, staff_name, staff_email, reason, token_hash unique, url_expires_at, ends_at, used_at, ended_at, timestamps; `workspace_api_usage`: workspace_id, day date, calls int; unique (workspace_id, day)); `routes/internal.php`; `app/Http/Middleware/InternalApiToken.php`, `EnsureWorkspaceWritable.php` (or the single check 3-R16 settles on), `EndSupportSession.php`; `app/Http/Controllers/Internal/V1/WorkspaceController.php`; `app/Http/Requests/Internal/*`; `app/Services/AdminApiService.php` (or methods on existing services), `SupportSessionService.php`; `resources/js/Pages/Workspace/Archived.tsx`; `resources/js/Components/WorkspaceBanners.tsx`; `docs/contract/admin-api/*.json`; tests `tests/Feature/Internal/AdminApiTest.php`, `SuspendArchiveTest.php`, `SupportSessionTest.php`, `tests/Feature/Internal/ContractTest.php`, `tests/Feature/Security/AdminApiSecurityTest.php`
- Modify: `bootstrap/app.php` (internal routes, no session/CSRF), `Idempotent` (3-R18), `Entitlements::set/unset` (source and reason), `WorkspaceUsage` (usage payload, 3-R20), per-agent limiter middleware (usage counter), `ResolveWorkspace` / `ResolveTokenWorkspace` (archived), `config/pm.php` + `.env.example`, `WorkspaceIsolationTest`

**Interfaces:**
- Produces: `WorkspaceUsage::snapshot(Workspace $w): array{billed_users:int, viewers:int, pending_billed_invitations:int, projects:int, storage_mb:int}` (Task 6 reuses it for events); `WorkspaceUsage::usage(Workspace $w): array` (snapshot plus agents, api_calls_30d, last_activity_at); `Workspace::isWritable(): bool` (not suspended, not archived) and the 3-R16 refusal; `SupportSessionService::start(Workspace, User $target, array $staff, string $reason, int $minutes): string` (URL), `consume(string $token): SupportSession`.

- [ ] **Step 1: Failing tests.** `AdminApiTest`: each endpoint in spec 9 returns the contract shape; `PUT …/entitlements` replaces plan rows only and leaves overrides; `PUT`/`DELETE …/entitlements/{key}` with `reason`; unknown key lists valid ones; suspend/unsuspend/archive/restore with `reason`; each write writes one audit row (`staff` with name/email, else `system`) with no token in it; `updated_since` and `cursor` page. `ContractTest`: every response and request example validates against `docs/contract/admin-api/*.json`. `SuspendArchiveTest` (Review Focus 4): in a suspended workspace, web create/edit/drag/comment/settings, REST, MCP, stdio, bulk, approval decisions and Undo are refused with `workspace_suspended`, reads and export work, the banner shows; archived: the archived page, Owner restores, Member can't, API/MCP 403. `SupportSessionTest`: the URL works once, expires after 5 minutes, signs in as the target without 2FA, every write is refused (3-D4), the banner shows, the session ends at `ends_at` and logs `staff.support_session_ended`; a non-member target is a 422; minutes > 60 is a 422. `AdminApiSecurityTest` (Review Focus 5): no token, a wrong token, a user's Sanctum or OAuth token → 401 with no detail; `_NEXT` works; unset token → 404 everywhere; a repeated Idempotency-Key replays without a second audit row; a missing key on a write is a 400.
- [ ] **Step 2: Run; see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Suite; browser check** suspended banner and refusal, archived page and Owner restore, support session banner; light, dark, 375px. Restore the demo workspace's state afterwards.
- [ ] **Step 5: Per-PR checklist and PR.** Owner runs the scan.

### Task 6 (PR 6): System events, billing link, billing banners

Branch `step-3/6-system-events`.

**Files:**
- Create: migration `…_create_system_events.php` (3-R22); `app/Models/SystemEvent.php`; `app/Services/SystemEventService.php`; `app/Listeners/RecordSystemEvents.php`; `app/Jobs/DeliverSystemEvent.php`; `app/Console/Commands/SendWorkspaceActivity.php`; `app/Services/BillingLink.php`; `app/Http/Controllers/BillingController.php`; `docs/contract/events/*.json`, `docs/contract/billing-token.json`; tests `tests/Feature/SystemEvents/SystemEventTest.php`, `DeliveryTest.php`, `tests/Feature/Billing/BillingLinkTest.php`, `BillingBannerTest.php`
- Modify: `config/pm.php` + `.env.example` (`PM_SYSTEM_EVENTS_URL`, `PM_SYSTEM_EVENTS_SECRET`, `PM_BILLING_LINK_SECRET`), `routes/console.php`, `WorkspaceBanners.tsx` (3-R24), Usage and limits page (plan name), workspace settings nav ("Billing"), `WorkspaceIsolationTest`

**Interfaces:**
- Consumes: `AuditRecorded`, `WorkspaceUsage::snapshot`.
- Produces: `SystemEventService::record(string $type, ?Workspace $w, array $data): ?SystemEvent`; `BillingLink::url(Workspace $w, User $owner): string`.

- [ ] **Step 1: Failing tests.** `SystemEventTest`: each 3-R21 source records one event with `id`, `type`, `occurred_at`, `workspace_id`, usage snapshot and its extra fields, valid against `docs/contract/events/*.json`; a Viewer → Member project role change sends `member.role_changed` with billed before/after; a rolled-back change records nothing; nothing is recorded when the URL is unset; `workspace.activity` at most once per workspace per day. `DeliveryTest` (Review Focus 5): the request carries the three headers and a signature that verifies with the secret; a 500 retries with the backoff and keeps the same event id; a 302 isn't followed; 2xx marks delivered; `retryUntil` is 24 h; the secret never appears in logs or `last_error`. `BillingLinkTest`: Owner gets the redirect with a token whose signature verifies, `exp - iat = 300`, a fresh nonce each time, claims valid against the schema; Admin and Member get 403; hidden when either env var is unset. `BillingBannerTest`: each 3-R24 case for Owner and Member; plan name on Usage.
- [ ] **Step 2: Run; see them fail.**
- [ ] **Step 3: Implement.** Sign `"{timestamp}.{raw body}"`; send the exact bytes that were signed.
- [ ] **Step 4: Suite; browser check** banners and the Billing link (with a local fake `PM_BILLING_URL`); light, dark, 375px. Reset entitlements on the demo workspace afterwards.
- [ ] **Step 5: Per-PR checklist and PR.**

### Task 7 (PR 7): Docs, demo seed, final review

Branch `step-3/7-docs-and-review`.

**Files:**
- Modify: `README.md` (watchers, notifications, mentions, reactions, Reverb setup and production process, polling fallback, internal admin API, system events, billing link and env vars, the new scheduled commands), `AGENTS.md` (tool table: `watch_issue`, `unwatch_issue`, `add_reaction`, `remove_reaction`, `get_updates`; mention syntax; warnings), `CLAUDE.md` ("Collaboration and events (step 3)": the bus, where notifications are written, channel rules, the writable check, contract schemas), `DemoSeeder` (through the services: watchers on WEB issues, a mention of demo by alex, a reaction, a few notifications), `PROGRESS.md`

- [ ] **Step 1:** Docs and seed; `php artisan migrate:fresh --seed` runs clean and the demo inbox shows items.
- [ ] **Step 2:** One Opus / high whole-branch review of step 3 (main before PR 1 .. HEAD), one Sonnet fix wave (test-first), a scoped Sonnet re-review of the fixes.
- [ ] **Step 3: Per-PR checklist and PR.**

---

## Self-review

- Spec coverage: 7.10 (watch button and count, auto-watch and setting, who adds watchers, Watching filter and dashboard section, inbox, triggers, batching, own-change rule, daily summary, approvals, mark read, live arrival, agent watch tools, `get_updates`, data) → Tasks 1, 2, 4; 7.10 infrastructure (event bus, admin API, system events, billing link, banners) → Tasks 1, 5, 6; 7.11 (mentions, non-member prompt, agent mentions and limit, key links with hover, live updates with Reverb and polling, reactions and tools, data) → Tasks 3, 4; 5.1 (live update animation rules, "Waiting on you" mentions, agent labels) → Tasks 3, 4; section 9 → Tasks 5, 6.
- Not in step 3 (by the spec): email notifications, voting, threaded replies, presence, group mentions (step 5), attachments and agent-run triggers (steps 6, B), "ready" filters (step 4).
