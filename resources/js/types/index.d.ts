export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
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
}

export interface Comment {
    id: number;
    body: string;
    body_html?: string;
    can_edit?: boolean;
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
    project: string;
    title: string;
    description: string | null;
    description_html?: string;
    type: string;
    priority: string;
    status: string;
    category: StatusCategory;
    /** Null while the issue isn't in a Done-category status. */
    resolution: Resolution | null;
    resolved_at: string | null;
    assignee: UserSummary | null;
    reporter: UserSummary | null;
    labels: string[];
    story_points: number | null;
    due_date: string | null;
    epic: IssueLink | null;
    parent: IssueLink | null;
    sprint: { id: number; name: string; state: string } | null;
    subtasks?: IssueLink[];
    epic_issues?: IssueLink[];
    comments?: Comment[];
    pending_approvals?: ApprovalRequest[];
    created_at: string;
    updated_at: string;
}

/** An agent's move waiting for a person (A2). `moved`: the issue left `from` since; it is in `now`. */
export interface ApprovalRequest {
    id: number;
    status: 'pending' | 'approved' | 'rejected' | 'expired' | 'stale';
    issue: string;
    title: string;
    /** Null only when that status was deleted since. */
    from: string | null;
    from_category: StatusCategory | null;
    to: string;
    now: string;
    moved: boolean;
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
    statuses: { name: string; category: StatusCategory }[];
    members: { name: string; email: string }[];
    labels: Label[];
    types: string[];
    priorities: string[];
    sprints: string[];
}
