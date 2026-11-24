import ApprovalCard from '@/Components/ApprovalCard';
import IssueTable from '@/Components/IssueTable';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ApprovalRequest, Issue, ProjectRole } from '@/types';
import EmptyState from '@/Components/EmptyState';
import { categories } from '@/Components/IssueFilters';
import { selectClass } from '@/Components/IssueFormModal';
import { resolutionLabels, resolutions } from '@/Components/ResolutionChip';
import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, FolderKanban } from 'lucide-react';

interface Summary {
    statuses: { name: string; count: number }[];
    types: Record<string, number>;
    total: number;
    open: number;
    overdue: number;
    active_sprint: {
        name: string;
        start_date: string | null;
        end_date: string | null;
        issue_count: number;
        issues_done: number;
        points_total: number;
        points_done: number;
    } | null;
}

const focusGroups: [string, string][] = [
    ['waiting_on_you', 'Waiting on you'],
    ['blocking_others', 'Blocking others'],
    ['urgent_or_overdue', 'Urgent or overdue'],
    ['due_soon', 'Due in the next 3 days'],
    ['in_progress', 'In progress'],
    ['to_do', 'To do'],
];
const groupings = [
    { value: 'focus', label: 'Focus' },
    { value: 'status', label: 'Status' },
    { value: 'priority', label: 'Priority' },
    { value: 'project', label: 'Project' },
    { value: 'sprint', label: 'Sprint' },
];
const priorities = ['highest', 'high', 'medium', 'low', 'lowest'];

/** Sections with a heading, in a fixed order for focus and priority, else as the rows come; empty ones never appear. */
function sections(issues: Issue[], group: string): { name: string; issues: Issue[] }[] {
    const keyOf = (i: Issue) =>
        group === 'status' ? i.status : group === 'priority' ? i.priority : group === 'project' ? i.project : group === 'sprint' ? (i.sprint?.name ?? 'No sprint') : (i.focus ?? 'to_do');
    const by = new Map<string, Issue[]>();
    issues.forEach((i) => by.set(keyOf(i), [...(by.get(keyOf(i)) ?? []), i]));
    const order = group === 'focus' ? focusGroups.map(([k]) => k) : group === 'priority' ? priorities : [...by.keys()].sort((a, b) => (a === 'No sprint' ? 1 : b === 'No sprint' ? -1 : 0));
    const labels = Object.fromEntries(focusGroups);
    return order.filter((k) => by.has(k)).map((k) => ({ name: group === 'focus' ? labels[k] : group === 'priority' ? k.charAt(0).toUpperCase() + k.slice(1) : k, issues: by.get(k)! }));
}

export default function Dashboard({
    myIssues,
    assignedOpen,
    pendingApprovals,
    projects,
    filters,
    group,
}: {
    myIssues: Issue[];
    assignedOpen: number;
    filters: { category?: string; resolution?: string };
    group: string;
    pendingApprovals: ApprovalRequest[];
    projects: { key: string; name: string; my_role: ProjectRole; summary: Summary }[];
}) {
    return (
        <AuthenticatedLayout header={<h1 className="text-display text-text">Your work</h1>}>
            <Head title="Dashboard" />

            <div className="space-y-8 px-4 pb-10 md:px-8">
                {/* Design system KPI tiles: a label and one number with its unit. */}
                <dl className="grid gap-4 sm:grid-cols-3">
                    {[
                        ['Assigned to you', `${assignedOpen} ${assignedOpen === 1 ? 'issue' : 'issues'}`],
                        ['Open in your projects', `${projects.reduce((n, p) => n + p.summary.open, 0)} issues`],
                        ['Overdue', `${projects.reduce((n, p) => n + p.summary.overdue, 0)} issues`],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-tile bg-card p-5 shadow">
                            <dt className="text-sm text-muted">{label}</dt>
                            <dd className="mt-1 text-heading-4xl text-text">{value}</dd>
                        </div>
                    ))}
                </dl>

                {/* A2: requests this person can decide (their agents', and every one in projects they administer). */}
                <section>
                    <h2 className="mb-3 text-heading-2xl text-text">Pending approvals</h2>
                    {pendingApprovals.length === 0 ? (
                        <EmptyState compact title="No requests waiting for you." hint="When an agent asks to move an issue to Done, it shows up here." />
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {pendingApprovals.map((a) => (
                                <ApprovalCard key={a.id} approval={a} canDecide linkIssue />
                            ))}
                        </div>
                    )}
                </section>

                <section>
                    <h2 className="mb-3 text-heading-2xl text-text">My issues</h2>
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        {(
                            [
                                ['category', 'Status category', categories],
                                ['resolution', 'Resolution', resolutions.map((r) => ({ value: r, label: resolutionLabels[r] }))],
                            ] as const
                        ).map(([name, label, choices]) => (
                            <select
                                key={name}
                                aria-label={label}
                                className={`${selectClass} !h-control-h w-auto ${filters[name] ? '!border-primary !text-primary' : ''}`}
                                value={filters[name] ?? ''}
                                onChange={(e) => router.get(route('dashboard'), { ...filters, [name]: e.target.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })}
                            >
                                <option value="">{label}</option>
                                {choices.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </select>
                        ))}
                        <select
                            aria-label="Group by"
                            className={`${selectClass} !h-control-h w-auto`}
                            value={group}
                            onChange={(e) => router.patch(route('profile.update'), { my_issues_group: e.target.value }, { preserveState: true, preserveScroll: true })}
                        >
                            {groupings.map((g) => (
                                <option key={g.value} value={g.value}>
                                    Group by {g.label.toLowerCase()}
                                </option>
                            ))}
                        </select>
                    </div>
                    {myIssues.length === 0 ? (
                        <EmptyState
                            icon={CircleCheck}
                            title={filters.category || filters.resolution ? 'No issues match these filters.' : "You're all caught up."}
                            hint={filters.category || filters.resolution ? 'Try removing a filter.' : 'Nothing open is assigned to you.'}
                        />
                    ) : group === 'focus' && (filters.category || filters.resolution) ? (
                        // Done issues have no focus group: the filtered list stays flat.
                        <IssueTable issues={myIssues} />
                    ) : (
                        <div className="space-y-5">
                            {sections(myIssues, group).map((s) => (
                                <div key={s.name}>
                                    <h3 className="mb-2 text-sm font-semibold text-text">
                                        {s.name} <span className="font-normal text-muted">{s.issues.length}</span>
                                    </h3>
                                    <IssueTable issues={s.issues} />
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section>
                    <h2 className="mb-3 text-heading-2xl text-text">Projects</h2>
                    {projects.length === 0 && (
                        <EmptyState
                            icon={FolderKanban}
                            title="No projects yet."
                            hint="Create your first project to start tracking issues."
                            action={{ label: 'Create project', onClick: () => router.visit(route('projects.index')) }}
                        />
                    )}
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {projects.map((p) => (
                            <ProjectCard key={p.key} project={p} />
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function ProjectCard({ project }: { project: { key: string; name: string; summary: Summary } }) {
    const { summary } = project;
    const sprint = summary.active_sprint;
    const max = Math.max(1, ...summary.statuses.map((s) => s.count));
    const pct = sprint && sprint.points_total > 0 ? Math.round((sprint.points_done / sprint.points_total) * 100) : 0;

    return (
        <article className="rounded-card bg-card p-5 shadow">
            <header className="flex items-baseline justify-between gap-2">
                <Link href={route('projects.show', project.key)} className="font-semibold text-text hover:underline">
                    <span className="text-primary">{project.key}</span> {project.name}
                </Link>
                <span className="text-xs text-muted">
                    {summary.open} open{summary.overdue > 0 && <span className="text-t-bad"> · {summary.overdue} overdue</span>}
                </span>
            </header>

            <dl className="mt-3 space-y-1">
                {summary.statuses.map((s) => (
                    <div key={s.name} className="flex items-center gap-2 text-xs">
                        <dt className="w-24 truncate text-muted">{s.name}</dt>
                        <dd className="flex flex-1 items-center gap-2">
                            <span className="h-2 rounded-pill bg-c-cobalt" style={{ width:`${(s.count / max) * 100}%`, minWidth: s.count ? '4px' : 0 }} />
                            <span className="text-text">{s.count}</span>
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="mt-4 border-t border-line pt-3 text-xs">
                {sprint ? (
                    <>
                        <div className="flex justify-between text-text">
                            <span className="font-medium">{sprint.name}</span>
                            <span>
                                {sprint.points_done}/{sprint.points_total} pts · {sprint.issues_done}/{sprint.issue_count} issues
                            </span>
                        </div>
                        <div
                            className="mt-1 h-2 overflow-hidden rounded-pill bg-card-2"
                            role="progressbar"
                            aria-label={`${sprint.name} points done`}
                            aria-valuenow={pct}
                            aria-valuemin={0}
                            aria-valuemax={100}
                        >
                            <div className="h-full bg-c-good" style={{ width:`${pct}%` }} />
                        </div>
                        {sprint.end_date && <p className="mt-1 text-muted">Ends {sprint.end_date}</p>}
                    </>
                ) : (
                    <p className="text-muted">No active sprint.</p>
                )}
            </div>
        </article>
    );
}
