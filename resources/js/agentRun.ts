import type { AgentRun, AgentRunChange } from '@/types';

export const isActive = (run: AgentRun) => run.status === 'queued' || run.status === 'running';

/** Which buttons the run panel offers (the server enforces the same rules). */
export function runActions(run: AgentRun) {
    const proposed = run.changes.filter((c) => c.status === 'proposed').length;
    const conflicts = run.changes.filter((c) => c.status === 'conflict').length;
    return { cancel: isActive(run), apply: proposed, discard: proposed + conflicts, propose: conflicts > 0 && !isActive(run) };
}

const text = (v: unknown): string => (Array.isArray(v) ? v.join(', ') : String(v ?? '')) || 'none';

/** One change as the diff shows it: what it touches, the value before (if any) and after. Comment bodies are plain text. */
export function describeChange(c: AgentRunChange): { field: string; before?: string; after: string } {
    const a = (c.after ?? {}) as Record<string, unknown>;
    if (c.kind === 'field') return { field: c.target ?? 'Field', before: text(c.before?.value), after: text(a.value) };
    if (c.kind === 'child_issue') return { field: 'New child issue', after: [a.title, a.type, a.story_points != null ? `${a.story_points} points` : null].filter(Boolean).join(', ') };
    if (c.kind === 'link') return { field: 'Link', after: `${text(a.type)} ${text(a.issue)}` };
    return { field: 'Comment', after: text(a.body) };
}

/** A tool call's arguments cut to one short line. */
export function shortArgs(args: unknown, max = 80): string {
    const s = args == null ? '' : JSON.stringify(args);
    return s.length > max ? `${s.slice(0, max)}…` : s;
}
