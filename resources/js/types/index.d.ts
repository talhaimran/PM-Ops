export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    /** Spec 7.5: what to do with successors when a due date moves. */
    date_shift: 'ask' | 'always' | 'never';
    my_issues_group?: string | null;
    /** Spec 7.10: watch what you report, are assigned or comment on. */
    auto_watch: boolean;
}

export type WorkspaceRole = 'owner' | 'admin' | 'member';

export interface CurrentWorkspace {
    id: number;
    name: string;
    slug: string;
    role: WorkspaceRole | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    workspace: CurrentWorkspace | null;
    workspaces: { name: string; slug: string }[];
    edition: 'self_hosted' | 'cloud';
    ai: { enabled: boolean };
    /** 3-R25: unread inbox items you can still see, in the current workspace. */
    unread_notifications: number;
};

export type ProjectRole = 'admin' | 'member' | 'viewer';

export interface Member {
    id: number;
    name: string;
    email: string;
    role: ProjectRole;
}

export interface Project {
    id: number;
    key: string;
    name: string;
    description: string | null;
    // Only on the project settings page: notes the project's admins wrote for agents.
    agent_instructions?: string | null;
    my_role: ProjectRole | null;
    members?: Member[];
}

export interface Invitation {
    id: number;
    email: string;
    role: ProjectRole;
    project?: { key: string; name: string };
}

export interface UserSummary {
    id: number;
    name: string;
    email: string;
}

export interface Label {
    id: number;
    name: string;
    color: string;
}

export type StatusCategory = 'to_do' | 'in_progress' | 'done';

export type Resolution = 'done' | 'wont_do' | 'duplicate' | 'cannot_reproduce';

export interface IssueLink {
    key: string;
    title: string;
    type?: string;
    status?: string;
    category?: StatusCategory;
    status_color?: string | null;
}

export interface Comment {
    id: number;
    body: string;
    /** Spec 7.11: sent back with an edit. */
    version: number;
    body_html?: string;
    can_edit?: boolean;
    /** 3-R13: one per emoji in use; `mine` = you (or your agent) reacted. */
    reactions?: { emoji: string; count: number; people: string[]; mine: boolean }[];
    author: UserSummary | null;
    agent?: AgentRef | null;
    created_at: string;
}

/** The agent that acted, when one did: its name and its owner's name. */
export interface AgentRef {
    name: string;
    owner: string;
}

export interface Issue {
    id: number;
    key: string;
    /** Spec 7.11: sent back with every edit. */
    version: number;
    project: string;
    title: string;
    description: string | null;
    description_html?: string;
    type: string;
    priority: string;
    status: string;
    category: StatusCategory;
    /** The status's custom colour; null = the category's. */
    status_color: string | null;
    /** Null while the issue isn't in a Done-category status. */
    resolution: Resolution | null;
    resolved_at: string | null;
    assignee: UserSummary | null;
    reporter: UserSummary | null;
    labels: string[];
    story_points: number | null;
    due_date: string | null;
    start_date: string | null;
    /** Only on the single issue: a successor starting before its predecessor's due date, on both issues (spec 7.5). */
    schedule_warning?: string | null;
    epic: IssueLink | null;
    parent: IssueLink | null;
    sprint: { id: number; name: string; state: string } | null;
    subtasks?: IssueLink[];
    epic_issues?: IssueLink[];
    comments?: Comment[];
    pending_approvals?: ApprovalRequest[];
    /** Spec 7.5: an unresolved blocker you can see. On lists and the single issue. */
    blocked?: boolean;
    /** Spec 5.1: the Focus group; only on My issues, null for done ones. */
    focus?: string | null;
    /** Spec 7.10, only on the single issue. */
    watchers_count?: number;
    watching?: boolean;
    watchers?: UserSummary[];
    /** Only on the single issue: linked issues you can see, by type name as read from this issue ("is blocked by"). */
    links?: Record<string, IssueLink[]>;
    created_at: string;
    updated_at: string;
}

/** An agent's move waiting for a person (A2). `moved`: the issue left `from` since; it is in `now`. `changed`: anything changed since (7.11). */
export interface ApprovalRequest {
    id: number;
    status: 'pending' | 'approved' | 'rejected' | 'expired' | 'stale';
    issue: string;
    title: string;
    /** Null only when that status was deleted since. */
    from: string | null;
    from_category: StatusCategory | null;
    from_color: string | null;
    to: string;
    now: string;
    moved: boolean;
    changed: boolean;
    reason: string | null;
    resolution: Resolution | null;
    duplicate_of: string | null;
    agent: AgentRef;
    expires_at: string;
    created_at: string;
    can_decide?: boolean;
}

export interface ActivityEntry {
    id: number;
    action: string;
    changes: Record<string, { from: unknown; to: unknown }> | null;
    /** On an approved or rejected agent request: which request, and the status it asked for. */
    approval_request?: { id: number; to: string };
    user: UserSummary | null;
    agent?: AgentRef | null;
    via: 'web' | 'api' | 'mcp';
    token_name: string | null;
    created_at: string;
}

export interface IssueOptions {
    /** The project's statuses in board order. */
    statuses: { name: string; category: StatusCategory; color: string | null }[];
    members: { name: string; email: string }[];
    labels: Label[];
    types: string[];
    priorities: string[];
    sprints: string[];
}
