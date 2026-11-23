# Step 1: Statuses, resolution, edit conflicts, history — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. One pull request per task; each is merged before the next starts. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Issues get a status from a workspace status library with a fixed category (To Do, In Progress, Done) and a resolution; "done" everywhere means the Done category, never a column's position; saves carry a version so nobody silently overwrites anybody; status, sprint and estimate changes are recorded in history tables for the step 10 reports.

**Architecture:** `statuses` is a workspace table; `column_statuses` maps a project's columns to statuses; `issues.status_id` replaces `issues.column_id`, and a card's column is the one its status maps to. One service (`StatusService`) owns the library and name lookups. Every status change still goes through `IssueService::update`, so resolution, the version bump, the history rows and the agent Done rule (`ApprovalService::needsApproval`) each live in exactly one place and web, REST and MCP get them for free.

**Tech Stack:** Laravel 13, Pest on MySQL, Inertia + React 18 + TypeScript, Tailwind tokens from `docs/design-system/tokens.json`, Headless UI, Motion. **No new dependencies.**

**Spec:** `docs/specs/pmops-spec.md` sections 5 (conventions), 5.1 (UI), 7.4, 7.11 "Edit conflict protection", 7.13 "History", 8 A2 "Before step 1", 11 Q9. Design system: `docs/design-system/components/ConflictDialog`, `Pill`, `BoardColumn`, `IssueCard`, `WarningConfirm`. No implementer or fixer edits the spec.

## Global Constraints

- CLAUDE.md is in force: logic in `app/Services`, thin web/API/MCP, Form Requests, Policies, API Resources, Pest on MySQL, the per-slice checklist, a case in `tests/Feature/Security/WorkspaceIsolationTest.php` for every new route, endpoint and MCP tool.
- Ponytail (full): the smallest complete change; reuse existing services, helpers and components; no new abstractions, options or config; finish every caller, test and fixture a change breaks; known limits as `shortcut: <limit>, <when to upgrade>`.
- "Statuses, not columns": done-ness, history, rules and reports use the status and its category, never a column's position. `Project::doneColumnId()` is removed in Task 1 and nothing replaces it by position.
- Status category is fixed: `to_do`, `in_progress`, `done`. Resolution is fixed: `done`, `wont_do`, `duplicate`, `cannot_reproduce` (labels "Done", "Won't do", "Duplicate", "Cannot reproduce").
- Status names are unique per workspace. A status appears in at most one column per project. A status can be deleted only when no issue (soft-deleted ones included) and no column uses it.
- Entering a Done-category status always sets a resolution and `resolved_at`; leaving clears both and logs a reopen. There is never a "Done but unresolved" issue.
- Only resolution = Done counts as completed (sprint progress, velocity).
- Agents never get settings tools: status library management is web-only for people. No new MCP tools in this step.
- An agent moving an issue into any Done-category status needs a person; `ApprovalService::needsApproval` is the one rule.
- Every history and activity row records who, the agent, and `via`, from the same source as `ActivityLogger` (`ActivityLogger::via()`, the approval binding).
- Audit events, verbatim: `status.created`, `status.renamed`, `status.recoloured`, `status.merged`, `status.deleted`. No secrets in any of them.
- Errors list valid options ("Unknown status "Doing". Valid statuses for WEB: …"). Not-found lookups throw `ValidationException::withMessages([...])->status(404)`.
- Internal identifiers stay: `pm_monkey`, the `pm` MCP handle, `PM_*` env vars, `pm.via`, `Pm*` classes.
- Screens follow 5.1: token classes only, timings from `resources/js/motion.ts`, `lucide-react`, light and dark, 375px, empty states, sentence case, colour never the only signal.
- Back up the dev DB with `mysqldump` before every migration on it. Never run two Pest runs at once.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; PR bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Decisions (confirmed by the owner 2026-11-22)

| # | Topic | Decision |
|---|---|---|
| 1-D1 | Who manages statuses (Q9, the step's only **(leaning)**) | As the spec leans: project Admins pick a library status or create one while editing columns; workspace Owners/Admins rename, recolour, merge and delete in Settings → Statuses. |
| 1-D2 | `issues.column_id` | Dropped in Task 1's migration (with `down()` and a migration test). `status_id` is the only place an issue's position on the workflow is stored. |
| 1-D3 | `version` on API/MCP | Required on every issue and comment edit, including each bulk item. Moves (`move_issue`, `POST …/move`, rank) are exempt. Breaking for existing scripts; documented in README and AGENTS.md. |
| 1-D4 | What bumps `version` | Field, label, sprint, status and resolution changes. A rank-only reorder does not. |
| 1-D5 | Merge limits | Only within the same category. Refused when a project has both statuses in different columns; the error names those projects. |
| 1-D6 | Category after creation | Not editable. |
| 1-D7 | Agent edits the resolution of an already-Done issue | Allowed at Write level, no approval. |
| 1-D8 | Delivery | One PR per task, merged before the next starts; branches `step-1/<n>-<slug>`. |
| 1-D9 | Scans | claude-security "scan changes" on PR 1 and PR 4, run by an Opus subagent at medium effort. |

## Rulings (manager's, inside the spec; say so if you want one changed)

| # | Topic | Ruling |
|---|---|---|
| 1-R1 | A project with one column | Its status migrates as `done` (it is the last column, so it is "done" today; "same behaviour as today"). |
| 1-R2 | Which statuses an issue may take | Only statuses mapped to one of its project's columns. Workflows (step 12) widen this. |
| 1-R3 | Renaming a column | Renames the column only. The status keeps its library name (it is shared); Owners/Admins rename statuses. |
| 1-R4 | New column before Task 5's picker exists | `ColumnRequest` takes optional `status` (an existing library status name) and `category` (default `in_progress`); without `status` a status named after the column is reused or created. |
| 1-R5 | Agent moving between two Done statuses | Needs approval (7.3: agents may move to any status "except Done-category statuses"). Staying on the same status never does. |
| 1-R6 | Old approval requests | The migration converts every row's `action` keys, not only pending ones, so readers know one shape. A pending request whose column is gone becomes `stale` with the existing "The column … was deleted." note. |
| 1-R7 | Existing Done issues | Task 2's migration sets `resolution = done` and `resolved_at` = the time of the last activity that moved the issue into its current status, else `updated_at`. |
| 1-R8 | "Close as…" target | The project's first Done-category status (by column position, then mapping position). No Done status in the project: 422 explaining that an Admin must add one. |
| 1-R9 | Deleting a status that history points at | Allowed (the spec blocks only on issues and columns). History rows keep `to_category`; their status ids become null. |
| 1-R10 | Estimates in history | Integers (`story_points`) for now. `shortcut:` hours with halves arrive with the estimation unit in step 10. |
| 1-R11 | Bulk update with a stale item | All or nothing, as bulk is today: 409 listing every conflicting key. |
| 1-R12 | Settings → Statuses has no "Create" button | The spec has statuses created from the column editor only. |
| 1-R13 | Order of statuses inside a column | The order they were added; the first is the drop target. `shortcut:` no reorder control, add one with workflows (step 12). |

## Schema (this is the whole schema change for the step; anything else stops and asks)

| Table | Columns |
|---|---|
| `statuses` (new, Task 1) | id, workspace_id (FK cascade), name (100), category (string), color (string 20, nullable; a palette name), description (500, nullable), timestamps; unique (workspace_id, name) |
| `column_statuses` (new, Task 1) | column_id (FK cascade), status_id (FK restrict), position (unsigned smallint); unique (column_id, status_id); index status_id |
| `issues` (Task 1) | add `status_id` (FK restrict, index (project_id, status_id)); **drop `column_id`** (1-D2) |
| `issues` (Task 2) | add `resolution` (string 20, nullable, indexed with project_id), `resolved_at` (timestamp, nullable) |
| `issue_status_history` (new, Task 3) | id, issue_id (FK cascade), from_status_id (FK null on delete, nullable), to_status_id (FK null on delete, nullable), to_category (string), user_id (nullable, null on delete), agent_id (nullable, null on delete), via (string 20), created_at; index (issue_id, created_at), (to_status_id, created_at) |
| `sprint_scope_events` (new, Task 3) | id, sprint_id (FK cascade), issue_id (FK cascade), event (`joined`/`left`), estimate (unsigned int, nullable: the issue's estimate at that moment), user_id, agent_id, via, created_at; index (sprint_id, created_at) |
| `estimate_history` (new, Task 3) | id, issue_id (FK cascade), from_estimate (nullable), to_estimate (nullable), user_id, agent_id, via, created_at; index (issue_id, created_at) |
| `issues`, `comments` (Task 4) | add `version` (unsigned int, default 1) |
| `activities` (Task 4) | add `issue_version` (unsigned int, nullable): the issue's version after that change, so "what changed since version N" is one query |
| `approval_requests` (Tasks 1, 2, 4) | no new columns. `action` JSON becomes `{to_status_id, to_status, from_status_id, reason}` (Task 1), gains `resolution`, `duplicate_of` (Task 2); `issue_version` is filled (Task 4) |

## Execution setup (owner's "plan B", adjusted)

| Task | Implementer | Task reviewer (ponytail-review method) | Scan |
|---|---|---|---|
| 1 Status library | Opus / high | Opus | Yes (Opus subagent, medium) |
| 2 Resolution | Sonnet | Sonnet / medium | No |
| 3 History | Sonnet | Sonnet / medium | No |
| 4 Edit conflicts | Opus / high | Sonnet / medium | Yes (Opus subagent, medium) |
| 5 Status settings | Sonnet | Sonnet / medium, `StatusService::merge` and its tests only | No |
| 6 Docs and review | Sonnet | none; one Opus / high whole-branch review, one fix wave, a scoped Sonnet re-review | No |

At most 2 fix rounds per task, then the manager rules inside the spec and lists the rulings in the PR body. The manager does the CLAUDE.md security checklist on every PR and stops at every PR until the owner says "merged". Dispatch ledger: `.superpowers/sdd/2026-11-22-step-1-statuses-history/progress.md` (Task 1 first confirms `.superpowers/` is git-ignored and adds the line if not).

## Review Focus

1. **Odd installs in the migration:** a one-column project, two projects whose same-named columns sit at different positions (name clash → "Name (KEY)"), a project with no issues, soft-deleted issues, a pending approval whose column was deleted. Every issue ends in the same column as before; nothing is lost. Tests in Task 1.
2. **Reaching Done another way:** `update_issue {status}`, `resolution` alone on an open issue, a column name that aliases a Done status, a second Done status in another column, `bulk_update_issues`, `PATCH`, `POST …/move`, `create_issue` in a Done status. Each opens an approval or is refused; none moves the issue. Tests in Tasks 1 and 2.
3. **Two saves at the same moment** with the same version: exactly one wins, the other gets the conflict; no lost update. Real-process test in Task 4.
4. **Another workspace's status:** a status name or id from workspace B is never accepted in A (issue input, column mapping, merge, rename), and errors don't reveal it exists. Tests in Tasks 1 and 5.
5. **Merge or delete racing a move:** an issue moved into a status while it is being merged or deleted never ends on a missing status, and no project ends with a status in two columns. Tests in Task 5.

---

### Task 1 (PR 1): Status library, migration, Done by category

Branch `step-1/1-status-library`.

**Files:**
- Create: `database/migrations/2026_11_24_000001_create_statuses_tables.php`, `app/Models/Status.php`, `app/Enums/StatusCategory.php`, `app/Services/StatusService.php`, `app/Http/Resources/StatusResource.php`
- Modify: `app/Models/Issue.php` (`status()`; `scopeDone`, `scopeOpen`; fillable), `app/Models/Column.php` (`statuses()` ordered by pivot `position`), `app/Models/Project.php` (remove `doneColumnId()`; `statuses()`), `app/Models/Workspace.php` (`statuses()`), `app/Services/IssueService.php` (`resolveInput`, `snapshot`, `sort`, `DISPLAY`, create/update hooks), `BoardService.php`, `ColumnService.php`, `ProjectService.php` (default columns get statuses; `issueOptions`), `ApprovalService.php`, `BacklogService.php`, `SprintService.php`, `DashboardService.php`, `UndoService.php`, `app/Http/Requests/Board/ColumnRequest.php` + `MoveIssueRequest.php`, resources `IssueResource`, `IssueCardResource`, `ColumnResource`, `BoardResource`, `BacklogResource`, `ProjectResource`, `ApprovalRequestResource`, MCP tools whose descriptions say "last column" (`MoveIssue`, `UpdateIssue`, `ListColumns`, `CreateColumn`, `UpdateColumn`, `GetProject`, `ProjectSummary`) and the `project://` resource, `resources/js/Components/StatusLozenge.tsx` (takes `category`, no guessing from column order) and its callers (`IssueTable`, `IssueView`, `IssueFormModal`, `ApprovalCard`, `AiBox`, `Pages/Projects/Board|Backlog|Issues|Settings.tsx`, `types/index.d.ts`), `database/seeders/DemoSeeder.php`, `AGENTS.md`, `tests/Pest.php` (`makeProject` builds statuses)
- Test: `tests/Integration/StatusMigrationTest.php`, `tests/Feature/Statuses/StatusLibraryTest.php`, `tests/Feature/Statuses/DoneByCategoryTest.php`, `tests/Feature/Security/StatusSecurityTest.php`, cases in `WorkspaceIsolationTest`; existing tests that used `column_id` or the last column updated

**Interfaces:**
- Produces:
  - `enum StatusCategory: string { ToDo = 'to_do'; InProgress = 'in_progress'; Done = 'done'; }` with `label(): string` ("To do", "In progress", "Done")
  - `Status` model: `workspace_id, name, category (StatusCategory), color, description`; `isDone(): bool`
  - `StatusService::forColumn(Workspace $ws, string $name, StatusCategory $category, string $projectKey, ?User $actor = null): Status` — reuse the same name with the same category; a clash creates `"{$name} ({$projectKey})"`; a created status logs `status.created` when `$actor` is given (the migration passes none)
  - `StatusService::resolveFor(Project $project, string $name, bool $firstOfColumn = false): Status` — a status name mapped in the project, or a column name when that column has exactly one status (`$firstOfColumn` = the web board drop: the column's first status). Unknown: `Unknown status "X". Valid statuses for WEB: A, B, C.`
  - `StatusService::attach(Column $column, Status $status): void` — refuses a status of another workspace, and one already in another column of the project
  - `Issue::scopeDone(Builder)`, `Issue::scopeOpen(Builder)` — by `statuses.category`
  - `ApprovalService::needsApproval(Issue $issue, Status $to, ?Agent $agent): bool` — `$agent` set, `$to->isDone()`, and `$to->id !== $issue->status_id`
  - `ApprovalService::requestDone(Issue $issue, User $owner, Agent $agent, Status $to, ?string $reason): ApprovalRequest`
  - JSON: issue `status` (status name) and `category`; columns `{name, position, statuses: [{name, category, color}], issues_count}`; approval `to` = status name

- [ ] **Step 1: Write the failing tests.**
  - `StatusMigrationTest` (roll back the batch, seed old-shape rows, migrate; like `AgentMigrationTest`): columns To Do / In Progress / Done become three statuses with categories `to_do`, `in_progress`, `done`; a second project with the same names reuses the same three rows; project `API` with "Done" as its *first* column gets "Done (API)" in `to_do`; a one-column project's status is `done` (1-R1); every issue, soft-deleted ones included, has a `status_id` whose column is its old column; `issues.column_id` is gone; a pending request's `action` equals `{to_status_id, to_status, from_status_id, reason}` (`toEqual`); a pending request to a deleted column is `stale`; `down()` restores `column_id` with the old values.
  - `StatusLibraryTest`: a new project's four default columns map to statuses To Do (`to_do`), In Progress and In Review (`in_progress`), Done (`done`); creating a column "QA" creates status QA `in_progress` and logs `status.created` once; a column created with `status: "Done"` maps the existing status and creates none; mapping a status already in another column of the project is a 422 naming that column; `status: "In Review"` and (one-status column) the column name both move an issue; an unknown status lists the valid ones; renaming a column leaves the issue's `status` unchanged (1-R3); deleting a column moves its issues to the target column's first status; responses carry `status` and `category`.
  - `DoneByCategoryTest`: reorder columns so Done is first: sprint done points, backlog "open" list, dashboard counts and sprint completion carry-over still treat Done-category issues as done and nothing else; an agent's move to a status in a *middle* column whose category is `done` returns `pending_approval`; to a second Done status from a Done status too (1-R5); to the last column when its status is `in_progress` just moves; `create_issue` by an agent in a Done status is refused with the A-L6 message naming the status.
  - `StatusSecurityTest` (Review Focus 4): workspace B's status name in `status` → the same "Unknown status" error with A's list only; `status_id` in a request body is ignored; a project Member and an agent can't create a column or status (existing messages).
- [ ] **Step 2: Run them to see them fail.** `php artisan test tests/Feature/Statuses tests/Integration/StatusMigrationTest.php tests/Feature/Security/StatusSecurityTest.php` → FAIL (no `statuses` table).
- [ ] **Step 3: Back up the dev DB** (`mysqldump` with the credentials in `.env` to `~/pm_monkey-before-step-1-1.sql`), then write the migration: create the two tables; per workspace, per project, columns by position → `forColumn` rule (first `to_do`, last `done`, others `in_progress`; one column → `done`); `status_id` from `column_id` in chunks; convert `approval_requests.action` (1-R6); drop `column_id`. Print each "Name (KEY)" it had to create.
- [ ] **Step 4: Implement** the enum, model, `StatusService`, then every caller in the files list. `resolveInput` returns the `Status`; the board groups cards by the column's statuses; `Issue::DISPLAY` loads `status` instead of `column`; sorting by `status` orders by the mapped column's position then the mapping position.
- [ ] **Step 5: Run the full suite** — `php artisan test` → all pass; `grep -rn "doneColumnId\|column_id" app resources/js database/seeders` returns only `column_statuses` uses.
- [ ] **Step 6: Browser check** (`app` preview, demo account) in light, dark and 375px: board, backlog, list, issue page, drag between columns, Undo. Leave no probe data.
- [ ] **Step 7: Per-PR checklist and PR.** `npm test`, `npm run build`, `vendor/bin/pint --test`, security review of the diff, scan (1-D9), PROGRESS.md line with any "Name (KEY)" made on the dev DB, AGENTS.md tool table, `gh pr create`.

### Task 2 (PR 2): Resolution

Branch `step-1/2-resolution`.

**Files:**
- Create: `database/migrations/2026_11_25_000001_add_resolution_to_issues.php`, `app/Enums/Resolution.php`, `resources/js/Components/ResolutionChip.tsx`
- Modify: `app/Services/IssueService.php` (resolution rule in `update`/`create`; `filter`), `ApprovalService.php` (action carries `resolution`, `duplicate_of`), `UndoService.php` (undo of a move restores status and resolution), `SprintService.php` + `BacklogService.php` + `DashboardService.php` (completed = resolution Done; My issues default not done), requests `UpdateIssueRequest`, `StoreIssueRequest`, `BulkUpdateIssueRequest`, `MoveIssueRequest`, `IssueListRequest`, `BoardFilterRequest`, resources `IssueResource`, `IssueCardResource`, MCP `MoveIssue`, `UpdateIssue`, `BulkUpdateIssues`, `SearchIssues`, `MyIssues`, `resources/js/Components/IssueView.tsx` ("Close as…" menu), `Filters.tsx`, `IssueCard`/board card (dimmed + chip), `Pages/Dashboard.tsx`, `AGENTS.md`
- Test: `tests/Feature/Issues/ResolutionTest.php`, `tests/Feature/Agents/ResolutionApprovalTest.php`, `tests/Integration/ResolutionMigrationTest.php`

**Interfaces:**
- Consumes: `Status::isDone()`, `StatusService::resolveFor`, `needsApproval` (Task 1).
- Produces:
  - `enum Resolution: string { Done = 'done'; WontDo = 'wont_do'; Duplicate = 'duplicate'; CannotReproduce = 'cannot_reproduce'; }` with `label()`
  - Input on web, REST and MCP: `resolution` (one of the four values; error lists them) and `duplicate_of` (an issue key)
  - JSON: `resolution`, `resolved_at` (ISO 8601 or null) beside `status` and `category`
  - Activity: action `reopened` when an issue leaves Done; `changes.resolution`; `changes.duplicate_of` = the original's key
  - Filters `category` (`to_do|in_progress|done`) and `resolution` on list, search, board and `my_issues`
  - `ApprovalService::requestDone(…, ?string $reason, ?Resolution $resolution = null, ?string $duplicateOf = null)`

- [ ] **Step 1: Failing tests.**
  - `ResolutionTest`: moving into a Done status sets `resolution = done` and `resolved_at = now`; with `resolution: "wont_do"` it stores that; moving out clears both and writes one `reopened` activity; `resolution` on an issue that is not Done and without a Done `status` is a 422 "Resolution applies to Done statuses. Move the issue to one of: Done."; `duplicate` without `duplicate_of` is a 422 "Send duplicate_of with the original issue's key, e.g. WEB-3."; `duplicate_of` unknown, in another workspace, or the issue itself is refused; a valid one is recorded in the activity `changes`; changing the resolution of a Done issue keeps `resolved_at`; "Close as…" with no Done status in the project gives the 1-R8 message; sprint done points and velocity count `done` only; completing a sprint carries over category ≠ Done only (a Won't do issue is neither completed nor carried over); `category=done` and `resolution=wont_do` filter list, search and board on REST and MCP; My issues leaves out Done-category issues by default; Undo of a move into Done restores the old status and clears the resolution.
  - `ResolutionApprovalTest` (Review Focus 2): an agent sending `resolution: "wont_do"` with a Done status gets `pending_approval`, and approving applies status and resolution together; `resolution` alone on an open issue is the 422 above, never a move; an agent changing the resolution of an already-Done issue succeeds with no request (1-D7) and the activity carries `agent_id`.
  - `ResolutionMigrationTest`: issues in Done-category statuses get `done` and a `resolved_at` (1-R7); others stay null.
- [ ] **Step 2: Run them to see them fail.**
- [ ] **Step 3: Back up the dev DB, write the migration** (columns + the 1-R7 backfill in chunks).
- [ ] **Step 4: Implement.** The rule sits in `IssueService::update` next to the status change (one place for web, REST, MCP, bulk and approvals). UI: `ResolutionChip` ("Resolved: Done ▾", a Headless UI menu, shown on the card for the toast's duration from `motion.ts`), cards with a resolution other than Done dimmed with the resolution named in text, "Close as…" in the issue page's menu (Duplicate asks for the original's key), category and resolution chips in `Filters`.
- [ ] **Step 5: Run the suite; browser check** drag to Done, change the chip, Undo, Close as Duplicate, filters; light, dark, 375px.
- [ ] **Step 6: Per-PR checklist and PR.**

### Task 3 (PR 3): History tables

Branch `step-1/3-history`.

**Files:**
- Create: `database/migrations/2026_11_26_000001_create_history_tables.php`, `app/Models/IssueStatusHistory.php`, `SprintScopeEvent.php`, `EstimateHistory.php`, `app/Services/HistoryRecorder.php`
- Modify: `app/Services/IssueService.php` (`create`, `update`), `SprintService.php` (`complete`, `delete`), `ActivityLogger.php` (expose the approval's agent so history uses the same attribution)
- Test: `tests/Feature/History/HistoryRecorderTest.php`, `tests/Integration/HistoryBackfillTest.php`

**Interfaces:**
- Consumes: `Status`, `Resolution` (Tasks 1, 2); `ActivityLogger::via()`.
- Produces: `HistoryRecorder::statusChanged(Issue $issue, ?Status $from, Status $to, ?User $actor): void`; `sprintChanged(Issue $issue, ?Sprint $from, ?Sprint $to, ?User $actor): void` (one `left` and/or one `joined` row with the issue's estimate at that moment); `estimateChanged(Issue $issue, ?int $from, ?int $to, ?User $actor): void`. Append-only models (no `updated_at`).

- [ ] **Step 1: Failing tests.**
  - `HistoryRecorderTest`: creating an issue writes one status row (`from` null, `to_category` of its status), a `joined` row when it starts in a sprint, and an estimate row when it has points; a status change writes one row with `to_category`; a change with the same status writes none; moving sprint A → B writes `left` A and `joined` B with the current estimate; re-estimating writes `from`/`to`; completing a sprint writes `left`/`joined` for each carried-over issue; deleting a sprint writes `left`; each row made over REST has `via = api`, over MCP `mcp`, on the web `web`, with `agent_id` set for agents; an approved move's row has the decider as `user_id` and the requesting agent as `agent_id`; no row is written when an agent's move is held.
  - `HistoryBackfillTest`: from seeded activities (`changes.status`, `changes.sprint`, `changes.story_points` as `IssueService::snapshot` names them) the migration builds the same rows, in order, with the activity's user, agent, via and time; an issue with no status activity gets one initial row at its `created_at`; a status name that no longer maps to a status in that project is skipped and counted in the migration's output; the estimate on a backfilled `joined` row is the issue's estimate at that time (walk the issue's activities in order, starting from the first `story_points` change's `from`, else the current value).
- [ ] **Step 2: Run them to see them fail.**
- [ ] **Step 3: Back up the dev DB, write the migration** (tables, then the backfill per issue in chunks with `eachById`).
- [ ] **Step 4: Implement** `HistoryRecorder` and its calls. In `IssueService::update` it reads the same before/after diff the activity log uses, so there is one diff.
- [ ] **Step 5: Run the suite.** No screens in this task.
- [ ] **Step 6: Per-PR checklist and PR;** PROGRESS.md records how many rows the dev DB backfill skipped.

### Task 4 (PR 4): Edit conflict protection

Branch `step-1/4-edit-conflicts`.

**Files:**
- Create: `database/migrations/2026_11_27_000001_add_versions.php`, `app/Exceptions/EditConflict.php`, `resources/js/Components/ConflictDialog.tsx`
- Modify: `app/Services/IssueService.php` (`update`, `updateMany`), `CommentService.php` (`update` takes the version), `UndoService.php` (`token`/`guard` on versions), `ApprovalService.php` (`issue_version` at request; compared on approve), `ActivityLogger.php` (`issue_version`), requests `UpdateIssueRequest`, `BulkUpdateIssueRequest`, `CommentRequest`, resources `IssueResource` (`version`, never dropped by `fields`), `CommentResource`, `ActivityResource`, MCP `UpdateIssue`, `BulkUpdateIssues`, `UpdateComment`, `GetIssue`, web `IssueController`, `resources/js/Components/IssueView.tsx` and comment editor (send `version`, open the dialog), `Toaster`/`undo.ts` if the undo payload shape changes, `bootstrap/app.php` only if the exception can't render itself, `AGENTS.md`, `README.md` (breaking change note)
- Test: `tests/Feature/Issues/EditConflictTest.php`, `tests/Feature/Issues/CommentConflictTest.php`, `tests/Integration/EditConflictLockTest.php`, `tests/Feature/Security/EditConflictSecurityTest.php`

**Interfaces:**
- Consumes: everything above; `PlanLimitReached` as the model for an exception that renders itself for REST, MCP and web.
- Produces:
  - `issues.version`, `comments.version` (start at 1); `version` in issue and comment JSON
  - `EditConflict` → REST `409 {"error":"conflict","message":"WEB-12 changed since version 3. Read it again and resend.","current_version":5,"changes":[{"field","from","to","by","agent","via","at"}],"issue":{…}}`; MCP the same message and JSON as a tool error; web redirects back with `flash.conflict = {key, version, yours, theirs, by, at}` and keeps the form input
  - Missing `version` on an edit: 422 "Send the version you read (the `version` field of get_issue)."
  - Web-only `overwrite: true` with the *current* version saves over and logs action `overwrote`

- [ ] **Step 1: Failing tests.**
  - `EditConflictTest`: an update with the current version saves and returns `version + 1`; a stale one changes nothing and returns the 409 with `current_version` and one `changes` entry per field changed since, naming the agent as "{Owner}'s agent" when one made it; a missing version is the 422 on REST, MCP and web; `move_issue`, `POST …/move` and rank need no version; a move that changes status bumps the version, a rank-only reorder doesn't (1-D4); a no-op update doesn't bump; a bulk update with one stale item changes nothing and names the key (1-R11); `fields=title` still returns `version`; Undo after someone else's edit stops with "Can't undo: …" naming the change, and still works after a rank-only reorder by someone else; approving a request whose issue changed since returns the 409 listing what changed, and `confirm_changed` then applies it; `overwrite` with the current version saves and logs `overwrote`, with a stale version conflicts again.
  - `CommentConflictTest`: the same for comment edits (stale → 409 with the current body, who and when).
  - `EditConflictLockTest` (Review Focus 3, real second connection like `WorkspaceOwnerLockTest`): two updates with the same version, one held inside its transaction: exactly one commits, the other gets `EditConflict`, and the issue holds the winner's value at `version + 1`.
  - `EditConflictSecurityTest`: a conflict response for an issue the caller can't see is the usual 404, not a 409; the `changes` list shows nothing from other projects; a Viewer and a read-only agent get their 403 before any conflict detail; `overwrite` is ignored on REST and MCP.
- [ ] **Step 2: Run them to see them fail.**
- [ ] **Step 3: Back up the dev DB, write the migration** (`version` default 1; `activities.issue_version`; pending approvals get `issue_version = 1`).
- [ ] **Step 4: Implement.** Compare and bump inside the existing transaction with the issue row locked (`lockForUpdate`), so the check and the write can't interleave. `ConflictDialog` per its design system page: title "{KEY} changed while you were editing", the spec's line under it ("Changed by Alex's agent 30 seconds ago. Review changes?", 7.11), "Your version" / "Their version" with who and when, "Keep theirs" (copies yours to the clipboard first), "Copy mine", "Save mine over theirs"; differences shown with strikethrough and weight, not colour alone; sides stack under 760px.
- [ ] **Step 5: Run the suite; browser check** with two tabs on one issue (description, title, a comment): the dialog, each of its three buttons, Undo; light, dark, 375px.
- [ ] **Step 6: Per-PR checklist and PR,** with the scan (1-D9). PR body states the breaking change (1-D3).

### Task 5 (PR 5): Settings → Statuses and the column editor

Branch `step-1/5-status-settings`.

**Files:**
- Create: `app/Http/Controllers/Web/StatusController.php`, `app/Http/Requests/Workspaces/UpdateStatusRequest.php`, `MergeStatusRequest.php`, `resources/js/Pages/Workspaces/Settings/Statuses.tsx`, `resources/js/Components/StatusMenu.tsx`
- Modify: `app/Services/StatusService.php`, `app/Policies/WorkspacePolicy.php` (`manageStatuses`: Owner/Admin), `routes/web.php` (`/settings/statuses` index, update, merge, destroy; column status attach/detach under the project), `app/Http/Controllers/Web/BoardController.php`, `ColumnService.php`, the workspace settings header (tab "Statuses"), `resources/js/Pages/Projects/Settings.tsx` (column editor), `StatusLozenge.tsx` (custom colour), `IssueView.tsx` (`StatusMenu` replaces the native select, the 2026-11-15 decision)
- Test: `tests/Feature/Statuses/StatusSettingsTest.php`, `tests/Feature/Statuses/StatusMergeTest.php`, `tests/Integration/StatusMergeLockTest.php`, `tests/Feature/Security/StatusSecurityTest.php` (more cases), `WorkspaceIsolationTest` cases for every new route

**Interfaces:**
- Consumes: `StatusService::forColumn|resolveFor|attach` (Task 1), history tables (Task 3).
- Produces: `StatusService::create(Workspace $ws, User $actor, string $name, StatusCategory $category): Status` (the column editor's "Create"; an existing name is a 422 that says to pick it instead, with its category), `rename(Status, User, string $name)`, `recolour(Status, User, ?string $color)` (palette: `slate, sky, amber, cobalt, coral, teal, sand, good`; null = category colour), `merge(Status $from, Status $into, User $actor): int` (issues moved), `delete(Status, User)`, `detach(Column, Status)`, `usage(Workspace): Collection` (`projects_count`, `issues_count` per status); routes `statuses.index|update|merge|destroy`, `columns.statuses.store|destroy`.

- [ ] **Step 1: Failing tests.**
  - `StatusSettingsTest`: Owners/Admins see every status with category, colour and "Used by 3 projects, 412 issues"; Members get 403; an agent token can't reach any route; rename to an existing name is a 422 listing the workspace's names; rename logs `status.renamed` with before/after, recolour `status.recoloured`; a colour outside the palette is a 422 listing it; delete works only with no issue (a soft-deleted one counts) and no column, else a 422 saying what uses it; delete logs `status.deleted`; category is not accepted on update (1-D6). Column editor: a project Admin attaches an existing library status, or creates one with a name and category (`status.created`); attaching one already in another column of the project is refused naming that column; detaching a status that issues in this project use is refused with the count; a column must keep at least one status; a project Member can't do any of it.
  - `StatusMergeTest`: merging Review into In Review moves every issue (soft-deleted too), column mappings (no duplicate row when a column had both), `issue_status_history` ids and pending approval targets, deletes Review, returns the count and logs `status.merged` once with both names and the count; different categories are refused (1-D5); a project with the two in different columns refuses the merge and names the project keys; merging into itself or across workspaces is refused.
  - `StatusMergeLockTest` (Review Focus 5): an issue moved into the source status by a second connection while the merge runs ends on the surviving status; a column attach racing a merge never leaves a project with one status in two columns.
- [ ] **Step 2: Run them to see them fail.**
- [ ] **Step 3: Implement.** Merge, rename and delete run in one transaction that locks the workspace row first (the same lock `WorkspaceMemberService` uses); attach and detach lock the project row as `ColumnService` does. Screens: `Statuses.tsx` as a settings card table (name with its pill, category, usage, a menu with Rename, Colour, Merge into…, Delete), `WarningConfirm` preview for merge ("Merge Review into In Review: 12 issues in 2 projects") and delete, `EmptyState` ("No statuses yet. They are created when a project adds a column."); the column editor lists each column's statuses with add (a Headless UI combobox: pick or "Create "QA"" with a category choice) and remove; `StatusMenu` is a Headless UI listbox grouped by category, each option a pill.
- [ ] **Step 4: Run the suite; browser check** the settings page (rename, recolour, merge, delete), the column editor and the status menu; light, dark, 375px; as alex (Admin) and sam (no access). Remove probe statuses.
- [ ] **Step 5: Per-PR checklist and PR.**

### Task 6 (PR 6): Docs, seed, final review

Branch `step-1/6-docs-and-review`.

- [ ] **Step 1:** README (statuses and categories, resolutions, edit conflicts and the `version` requirement, Settings → Statuses), AGENTS.md (statuses instead of "the last column", `resolution` / `duplicate_of`, `version` and the conflict error, the tool table), CLAUDE.md (a "Statuses (step 1)" section: `StatusService`, `Issue::scopeDone`, where resolution, version and history are written; the old `Project::doneColumnId()` lines removed), DemoSeeder (one Won't do issue, one status with a custom colour; still built through the services), PROGRESS.md shortcut list refreshed (the step U lines this step closes: native status select, Undo's "changed meanwhile").
- [ ] **Step 2:** One Opus / high whole-branch review of `df7d3e6..HEAD` by the ponytail-review method (Must fix / Should fix / Nice to have, each with a failing case; verdict and "Lean: -N lines possible").
- [ ] **Step 3:** One fix wave, test-first, security findings proven in `tests/Feature/Security/`; then a Sonnet re-review scoped to the fixes.
- [ ] **Step 4:** `ponytail:ponytail-debt`; list the step's new `shortcut:` comments in the PR body.
- [ ] **Step 5:** Walk spec 7.4, 7.11 (conflicts), 7.13 (history), section 5's step 1 lines and A2's "Before step 1" note line by line in the PR body, each mapped to a passing test file; per-PR checklist and PR.

## Carried over, not built here

- Request-time SSRF guard: required before step B's first AI provider call (CLAUDE.md "Agents and AI").
- Disconnecting a single OAuth client.
- Known and accepted: an agent at the Delete level can soft-delete its owner's comment.
- From the spec, later steps: `duplicates` links (step 2; until then the original's key is in the activity log), Done clearing after N days and the estimation unit (step 10), `history_days` on Free (step 10), workflows and `get_transitions` (step 12).
