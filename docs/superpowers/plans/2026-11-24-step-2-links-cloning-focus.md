# Step 2: SSRF guard, issue links, start dates, cloning, Focus grouping. Implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build spec step 2 (issue links with `start_date` and date shifting, cloning, the My issues "Focus" grouping), with the request-time SSRF guard for AI calls first.

**Architecture:** The guard is one service that turns an AI endpoint into checked, IP-pinned, no-redirect HTTP client options, so whichever AI library step B picks can use it. Links are one `issue_links` table (stored once, forward direction) behind a `LinkService` that web, REST and MCP share. Date shifting and the blocked/successor warnings live in that service and in `IssueService::update`. Cloning is `IssueService::clone`. Focus grouping is computed in `DashboardService`.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, Pest, Inertia + React + TS, laravel/mcp, Sanctum/OAuth agents. No new dependencies.

**Spec:** `docs/specs/pmops-spec.md`, sections 7.5 (links), 7.9 (cloning), 5.1 (Focus grouping), 8 A5 and CLAUDE.md "Agents and AI" (SSRF guard).

## Global Constraints

- CLAUDE.md is in force: logic in `app/Services`, thin web/API/MCP, Form Requests, Policies, API Resources, Pest on MySQL, the per-slice checklist, a `WorkspaceIsolationTest` case for every new route, endpoint and MCP tool, the AGENTS.md tool table updated when tools change.
- Ponytail (full): the smallest complete change; reuse existing services and components; known limits as `shortcut: <limit>, <when to upgrade>`.
- Link types, verbatim: `blocks` / `is blocked by`, `precedes` / `follows`, `relates to` / `relates to`, `duplicates` / `is duplicated by`, `clones` / `is cloned by`. Stored once in the forward direction; reverse names are accepted as input.
- Finish-to-start only. No loops for `blocks` and `precedes`; the error names the loop (`WEB-1 → WEB-2 → WEB-1`).
- Links may cross projects in one workspace, never across workspaces.
- Deleting an issue hides its links; restoring brings them back. Adding or removing a link is logged on both issues.
- `start_date` is a new nullable date on issues. Per-user `date_shift`: `ask` | `always` | `never`, default `ask`.
- Date shifting: each conflicting successor moves later by just enough, keeping its duration, down the chain. Resolved issues never move. Each shift is logged: "dates shifted because WEB-12 moved".
- Agents: `link_issues` / `unlink_issues` (`{"from":"WEB-12","type":"blocks","to":"WEB-15"}`); `get_issue` returns links grouped by type with each linked issue's category; `update_issue` takes `shift_successors: true|false`, defaulting to the owner's saved choice; `clone_issue`.
- Cloning copies, into the same project: title, description, type, labels, estimate and, if asked, sub-tasks and links. Never comments or history. Linked with `clones`.
- AI calls: no outbound request to an AI endpoint except through the guard; reuse `AiEndpoint`'s ranges; the AI key is read only through `AiSettingsService::key()`.
- Errors list valid options; not-found is `ValidationException::withMessages([...])->status(404)`. Viewers and read-only tokens never write.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; PR bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Decisions (owner, 2026-11-24)

| # | Topic | Decision |
|---|---|---|
| 2-D1 | Existing Duplicate issues | The migration turns step 1's recorded `duplicate_of` keys (activity log) into `duplicates` links when the original still exists in the same workspace. |
| 2-D2 | Links to issues you can't see | Hidden from you (not counted or hinted); linking to an issue you can't see is the usual 404. |
| 2-D3 | Agent permission level | `link_issues`, `unlink_issues` and `clone_issue` need Write. |
| 2-D4 | The soft warnings (moving a blocked issue into an In Progress-category status; starting a successor whose predecessor is unresolved) | Web: a `WarningConfirm`, then the move. **REST and MCP: refused (422) with the warning text until the call is resent with `confirm: true`**, because some agents ignore warnings. Bulk: all or nothing, listing every warned key. The date-shift flow is not a "warning" here: it keeps the spec's behaviour (below). |
| 2-D5 | Delivery | One PR per task, merged before the next starts; branches `step-2/<n>-<slug>`. |
| 2-D6 | Scans | claude-security "scan changes" on PR 1 (guard) and PR 2 (links), run by the owner in a separate chat. |

## Rulings (manager, within the spec)

| # | Topic | Ruling |
|---|---|---|
| 2-R1 | Guard and local models | `http://localhost` stays allowed on self-hosted only (step A's `AiEndpoint` rule); every other host must resolve only to public addresses. |
| 2-R2 | Guard shape | `AiRequestGuard::options(string $endpoint): array` returns Guzzle options (`allow_redirects => false`, `curl => [CURLOPT_RESOLVE => ["host:port:ip"]]`, timeouts); `AiRequestGuard::http(string $endpoint): PendingRequest` wraps them. Step B passes the options to its library (Prism's `withClientOptions`, or the Laravel Http client). |
| 2-R3 | Who may link | Editing the source issue (contribute in its project) and seeing the target. Unlinking: contribute in either issue's project. |
| 2-R4 | Loop race | No lock for loop checks: two links that close a loop at the same instant can both commit. Every graph walk keeps a visited set, so a loop never hangs anything (`shortcut:`). |
| 2-R5 | Conflict rule | A successor conflicts when its start is before its predecessor's due date. A successor with no `start_date` uses its `due_date`; with neither, nothing to check. |
| 2-R6 | "Ask every time" | As the spec says: the date change is saved, nothing shifts, and the response (web: a dialog; API/MCP: `proposed_shifts` plus a warning) lists the proposed dates. `shift_successors: true` applies them. |
| 2-R7 | Clone details | The clone starts in the project's first To Do-category status, unassigned, outside any sprint, with no epic or dates. "Include links" copies `blocks`, `precedes` and `relates to` links only. Versions, custom fields and attachments arrive in their own steps. |
| 2-R8 | Focus groups | "Waiting on you" covers pending approval requests from your agents (mentions arrive in step 3). "Urgent" is priority Highest. "Blocking others" means the issue blocks an unresolved issue. The chosen grouping is stored per user (`users.my_issues_group`, null = Focus). |
| 2-R9 | Where `date_shift` is set | The profile page, and "Remember my choice" in the shift dialog. |
| 2-R10 | Undo | Adding or removing a link on the web offers Undo. Undo of a move that left Duplicate now restores `duplicate_of` from the link (closes the step 1 shortcut in `UndoService`). |
| 2-R11 | Filters | `blocked` (has an unresolved blocker) and `blocked_by` (an issue key) on web, REST and MCP list filters. "Ready" filters wait for step 4 (7.12). |

## Execution (cost-lean, as step 1)

| Task | Implementer | Task reviewer | Scan |
|---|---|---|---|
| 1 SSRF guard | Opus / high | Sonnet / medium | Yes (owner) |
| 2 Links | Opus / high | Sonnet / medium | Yes (owner) |
| 3 Start dates and shifting | Sonnet | Sonnet / medium, `shift()` and its tests only | No |
| 4 Cloning | Sonnet | none (manager reads the diff) | No |
| 5 Focus grouping | Sonnet | none (manager reads the diff) | No |
| 6 Docs and review | Sonnet | one Opus / high whole-branch review, one fix wave, a scoped Sonnet re-review | No |

At most 2 fix rounds per task, then the manager rules. Browser checks are the manager's, in the `app` preview, light, dark and 375px, with probe data removed.

## Review Focus

1. **A host that resolves to two addresses, one private** (or a DNS answer that changes between the check and the connect): the guard refuses, and the connection can only go to the checked IP. Tests in Task 1.
2. **A redirect to `169.254.169.254`** from an allowed host: never followed. Test in Task 1.
3. **A link to an issue in a project the caller can't see, or in another workspace**: a 404 that doesn't reveal the issue exists, and a caller who can see one side never sees the other side's title or key. Tests in Task 2.
4. **A loop through three or more issues, and date shifting along a chain that contains a resolved issue or a loop**: the error names the loop; shifting stops at resolved issues and never runs forever. Tests in Tasks 2 and 3.
5. **Deleting, restoring and purging a linked issue**: links hide, come back, and vanish with the purge; a blocked badge clears when the blocker is deleted or resolved. Tests in Task 2.

---

### Task 1 (PR 1): Request-time SSRF guard

Branch `step-2/1-ssrf-guard`.

**Files:**
- Create: `app/Services/AiRequestGuard.php`, `tests/Feature/Security/AiRequestGuardTest.php`
- Modify: `app/Services/AiEndpoint.php` (expose `isPublicIp(string $ip): bool` over the existing v4/v6 checks), `CLAUDE.md` (the "Agents and AI" line points at the guard)

**Interfaces:**
- Produces: `AiRequestGuard::options(string $endpoint): array` (throws `ValidationException` with a plain message when refused); `AiRequestGuard::http(string $endpoint): PendingRequest`; a resolver seam (`AiRequestGuard::resolveUsing(?Closure $fn)`, tests only) returning a host's A and AAAA records.

- [ ] **Step 1: Failing tests** in `AiRequestGuardTest`:
  - a static problem (`AiEndpoint::problem()`) is refused with the same message;
  - a host resolving to `[93.184.216.34]` gives options with `allow_redirects === false` and `curl[CURLOPT_RESOLVE] === ['api.example.com:443:93.184.216.34']`;
  - a host resolving to `[93.184.216.34, 10.0.0.5]` is refused (any private answer refuses);
  - `127.0.0.1`, `169.254.169.254`, `::ffff:10.0.0.1`, `fd00::1` and `168.63.129.16` answers are refused;
  - a host that resolves to nothing is refused ("couldn't be resolved");
  - `http://localhost:11434` is allowed when self-hosted (pinned to 127.0.0.1) and refused on Cloud;
  - `http()` with `Http::fake([... => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])])` returns the 302 without a second request (`Http::assertSentCount(1)`);
  - an IPv6 public answer is pinned in brackets (`host:443:[2606:4700::1]`);
  - an endpoint from `PM_AI_*` config goes through the same checks.
- [ ] **Step 2: Run them; see them fail.**
- [ ] **Step 3: Implement** with `dns_get_record($host, DNS_A | DNS_AAAA)` behind the resolver seam; pin the first answer after every answer passes; port from the URL or the scheme default.
- [ ] **Step 4: Run the suite.** No browser check (nothing on screen).
- [ ] **Step 5: Per-PR checklist and PR.** The owner runs the claude-security scan.

### Task 2 (PR 2): Issue links

Branch `step-2/2-links`.

**Files:**
- Create: migration `…_create_issue_links.php` (`issue_links`: source_issue_id, target_issue_id, type, created_by, agent_id, via, timestamps; unique (source, target, type); both FKs cascade on delete, indexed) plus the 2-D1 backfill; `app/Enums/LinkType.php`; `app/Models/IssueLink.php`; `app/Services/LinkService.php`; `app/Http/Requests/Issues/LinkRequest.php`; `app/Mcp/Tools/LinkIssues.php`, `UnlinkIssues.php`; `resources/js/Components/IssueLinks.tsx`; tests `tests/Feature/Links/LinkTest.php`, `tests/Feature/Links/BlockedWarningTest.php`, `tests/Feature/Security/LinkSecurityTest.php`, `tests/Integration/DuplicateLinkMigrationTest.php`
- Modify: `IssueService` (`filter()` gains `blocked` and `blocked_by`; "Close as Duplicate" creates the link; the 2-D4 gate in `update`/`updateMany`), `BoardService::move` (2-D4 gate), `UndoService` (2-R10), `IssueResource` (`links` grouped by type, visible ones only; `blocked: bool`), `GetIssue`, `MoveIssue`, `UpdateIssue`, `BulkUpdateIssues` (`confirm`), `PmServer::$tools`, routes (web and `/api/v1`: `POST /issues/{key}/links`, `DELETE /issues/{key}/links`), `IssueView.tsx` (Links section, Blocked badge), `Board.tsx`/`IssueTable.tsx` (Blocked badge), board drag and status menu (`WarningConfirm` for 2-D4), AGENTS.md tool table, `WorkspaceIsolationTest`

**Interfaces:**
- Consumes: `IssueService::resolve`, `ActivityLogger::log` and `attribution()`, `ApprovalService::needsApproval` (a gated move into Done still opens an approval after `confirm`).
- Produces: `LinkService::link(Issue $from, string $type, Issue $to, User $actor): IssueLink` (reverse type names accepted; normalised forward); `unlink(Issue $a, string $type, Issue $b, User $actor): void`; `visibleLinks(Issue $issue, User $viewer): array` (grouped by display name, each `{key, title, category, status}`); `blockers(Issue $issue): Collection` (unresolved); `unresolvedPredecessors(Issue $issue): Collection`; `warnings(Issue $issue, Status $to): list<string>` (2-D4 text).

- [ ] **Step 1: Failing tests.**
  - `LinkTest`: link with each type and its reverse name stores one forward row; a duplicate link is a 422; a self-link is a 422; a `blocks` loop `WEB-1 → WEB-2 → WEB-3 → WEB-1` is refused with that text; `precedes` the same; `relates to` may loop; activity "linked"/"unlinked" on both issues with who, agent and via; `get_issue` and the REST issue JSON group links by type with category; cross-project in one workspace works; deleting WEB-2 hides its links from WEB-1, restoring shows them, `issues:purge` removes the rows; a Blocked badge (`blocked: true`) clears when the blocker resolves or is deleted; `blocked` and `blocked_by` filters on REST, MCP `search_issues` and the web list; "Close as Duplicate" creates a `duplicates` link; Undo of a move off Duplicate restores `duplicate_of`; web link/unlink flash Undo.
  - `BlockedWarningTest` (2-D4): a person on the web moving a blocked issue to In Progress gets the confirm, then moves; REST `PATCH`/`move` and MCP `move_issue`/`update_issue` without `confirm` are a 422 with "WEB-15 is blocked by WEB-12 (In Progress), which isn't resolved. Send confirm: true to move it anyway." and nothing changes; with `confirm: true` it moves; starting a successor whose predecessor is unresolved, the same; `bulk_update_issues` with one warned item changes nothing and lists it; a move into Done still opens an approval for an agent after `confirm`.
  - `LinkSecurityTest` (Review Focus 3): linking to an issue in an unseen project or another workspace is the usual 404 text; a Viewer and a read-only agent get 403; an issue's links never include an unseen issue's key or title (REST, MCP, web props); an agent at Read level can't link (2-D3).
  - `DuplicateLinkMigrationTest` (2-D1): a Duplicate issue whose activity names WEB-3 gets one `duplicates` link to WEB-3; one whose original was purged or is in another workspace gets none and is counted in the migration's note; re-running writes no duplicates.
- [ ] **Step 2: Run them; see them fail.**
- [ ] **Step 3: Implement.** Loop check: a breadth-first walk with a visited set (2-R4). Visibility: filter links through `$viewer->accessibleProjects()`, one query per issue (no N+1 on lists: list rows carry only `blocked`).
- [ ] **Step 4: Run the suite; browser check** the links section (add, remove, Undo), the Blocked badge on board and list, the confirm on drag and status menu; light, dark, 375px. Remove probe links.
- [ ] **Step 5: Per-PR checklist and PR.** The owner runs the claude-security scan.

### Task 3 (PR 3): `start_date` and date shifting

Branch `step-2/3-start-dates`.

**Files:**
- Create: migration `…_add_start_date_and_date_shift.php` (`issues.start_date` date nullable, indexed; `users.date_shift` string default `ask`); `resources/js/Components/DateShiftDialog.tsx`; tests `tests/Feature/Links/DateShiftTest.php`
- Modify: `StoreIssueRequest`/update request (`start_date` `date_format:Y-m-d`, not after `due_date`; `shift_successors` boolean), `LinkService` (`shift()`), `IssueService::update`, `UpdateIssue` tool and REST docs, `IssueResource` (`start_date`; `schedule_warning` when a predecessor's due date is after this issue's start), `IssueView.tsx` (start date field, the warning on both issues), `ProfileUpdateRequest` + `Profile/Edit.tsx` (date_shift), AGENTS.md

**Interfaces:**
- Consumes: `LinkService` (Task 2).
- Produces: `LinkService::proposedShifts(Issue $moved): list<array{key: string, start_date: ?string, due_date: ?string}>`; `LinkService::shift(Issue $moved, User $actor): int` (issues moved). `update_issue`/`PATCH` responses gain `proposed_shifts` when "ask" left them unapplied.

- [ ] **Step 1: Failing tests** in `DateShiftTest`: WEB-1 precedes WEB-2 (start 10th, due 12th); WEB-1's due moves from the 9th to the 14th → with `always`, WEB-2 becomes 14th–16th (duration kept) and its activity says "dates shifted because WEB-1 moved"; a chain WEB-1 → WEB-2 → WEB-3 shifts both; a resolved WEB-2 doesn't move and WEB-3 isn't shifted through it; a loop doesn't hang (Review Focus 4); `never` saves the date and shifts nothing; `ask` saves the date, shifts nothing and returns `proposed_shifts` with a warning; `shift_successors: true` overrides `never`; a successor without `start_date` is checked by `due_date` (2-R5); `start_date` after `due_date` is a 422; the web dialog's "Remember my choice" saves `date_shift`; the profile page sets it.
- [ ] **Step 2: Run them; see them fail.**
- [ ] **Step 3: Implement.** Shifting runs in `IssueService::update`'s transaction; each shifted issue goes through the same update path (version bump, activity, attribution).
- [ ] **Step 4: Run the suite; browser check** the start date field, the dialog (Shift / Keep / Remember), the warning on both issues, the profile setting; light, dark, 375px.
- [ ] **Step 5: Per-PR checklist and PR.**

### Task 4 (PR 4): Cloning

Branch `step-2/4-clone`.

**Files:**
- Create: `app/Mcp/Tools/CloneIssue.php`, `app/Http/Requests/Issues/CloneIssueRequest.php`, `tests/Feature/Issues/CloneTest.php`
- Modify: `IssueService` (`clone()`), routes (web and REST `POST /issues/{key}/clone`), `IssueView.tsx` ("Clone" in the issue menu with the two options), `PmServer::$tools`, AGENTS.md, `WorkspaceIsolationTest`

**Interfaces:**
- Consumes: `IssueService::create`, `LinkService::link` (Task 2).
- Produces: `IssueService::clone(Issue $issue, User $actor, bool $subtasks = false, bool $links = false): Issue`.

- [ ] **Step 1: Failing tests** in `CloneTest`: a clone copies title, description, type, labels and story points, starts in the first To Do status, unassigned, outside sprints, no epic or dates (2-R7), and is linked `WEB-9 clones WEB-4`; `subtasks: true` clones each sub-task under the clone; `links: true` copies blocks/precedes/relates links only; comments and activity history are never copied; a Viewer and a read-only agent get 403; an agent at Write can clone (2-D3); plan limits apply if issues are counted.
- [ ] **Step 2–5:** run, implement in one transaction, suite, browser check (menu, options, the new issue opens), checklist and PR.

### Task 5 (PR 5): Focus grouping on My issues

Branch `step-2/5-focus`.

**Files:**
- Create: migration `…_add_my_issues_group.php` (`users.my_issues_group` string nullable); `tests/Feature/Lists/FocusGroupingTest.php`
- Modify: `DashboardService` (`focusGroup(Issue, User, …): string`, grouping by focus/status/priority/project/sprint), `IssueListController` / dashboard props, `Dashboard.tsx` (grouped sections, a grouping switcher, remembered via a small PATCH to the profile), `MyIssues` MCP tool (each row's `focus` group name), AGENTS.md

**Interfaces:**
- Consumes: `LinkService::blockers` (Task 2), pending approvals of the user's agents.
- Produces: groups, in order: `waiting_on_you`, `blocking_others`, `urgent_or_overdue`, `due_soon`, `in_progress`, `to_do` (labels "Waiting on you", "Blocking others", "Urgent or overdue", "Due in the next 3 days", "In progress", "To do").

- [ ] **Step 1: Failing tests** in `FocusGroupingTest`: one open issue per group lands in exactly its first qualifying group (an issue that is both blocking others and overdue is in "Blocking others"); Done-category issues aren't listed; switching to priority/status/project/sprint regroups and is remembered after reload; MCP `my_issues` rows carry `focus`; no N+1 (query count stays flat from 5 to 50 issues).
- [ ] **Step 2–5:** run, implement, suite, browser check (groups, switcher, empty groups hidden), checklist and PR.

### Task 6 (PR 6): Docs, final review

Branch `step-2/6-docs-and-review`.

- [ ] **Step 1:** README (links, start dates and shifting, cloning, Focus, the AI guard), AGENTS.md (the three new tools, `confirm` for warned moves, `shift_successors`, `proposed_shifts`), CLAUDE.md (a "Links (step 2)" section: `LinkService`, the 2-D4 gate, visibility rule), PROGRESS.md shortcut list, DemoSeeder (a blocks link, a precedes chain with dates, one clone; through the services).
- [ ] **Step 2:** One Opus / high whole-branch review of `3611540..HEAD` (ponytail-review method).
- [ ] **Step 3:** One fix wave, test-first; then a Sonnet re-review scoped to the fixes.
- [ ] **Step 4:** `ponytail:ponytail-debt`; the step's new `shortcut:` comments in the PR body.
- [ ] **Step 5:** Walk spec 7.5, 7.9 and 5.1's Focus line in the PR body, each mapped to a passing test; per-PR checklist and PR.

## Carried over, not built here

- Disconnecting a single OAuth client (deferred).
- Known and accepted: an agent at the Delete level can soft-delete its owner's comment.
- Later steps: "ready" filters (step 4), attachments (step 6), versions (step 8), custom fields (step 11), the timeline's dependency arrows (step 10), @mentions in "Waiting on you" (step 3).
