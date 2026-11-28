import { describeChange } from '@/agentRun';
import { AgentRun, AgentRunChange } from '@/types';
import { Link2, MessageSquare, Pencil, Plus } from 'lucide-react';

const icons = { field: Pencil, child_issue: Plus, link: Link2, comment: MessageSquare };
const button = 'inline-flex h-control-h-sm items-center justify-center rounded-ctl px-3 text-sm transition-colors duration-instant disabled:cursor-not-allowed disabled:opacity-50';

/**
 * Design system change-set diff: one row per change an agent run proposed (kind icon, field, before → after). A conflict
 * row (warn tint) says what changed since, and can only be discarded. Values are model output: always plain text.
 */
export default function ChangeSetDiff({ run, busy, onApply, onDiscard }: { run: AgentRun; busy: boolean; onApply: (c: AgentRunChange) => void; onDiscard: (c: AgentRunChange) => void }) {
    return (
        <ul className="overflow-hidden rounded-card border border-line bg-card" aria-label="Changes proposed">
            {run.changes.map((c) => {
                const d = describeChange(c);
                const Icon = icons[c.kind];
                const settled = c.status === 'applied' || c.status === 'discarded';
                return (
                    <li key={c.id} className={`grid grid-cols-[24px_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1 border-b border-line px-3.5 py-2.5 text-sm last:border-b-0 ${c.status === 'conflict' ? 'tint-warn' : ''} ${settled ? 'text-muted' : ''}`}>
                        <span className="flex h-6 w-6 items-center justify-center rounded-sm bg-card-2 text-muted">
                            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="text-xs font-semibold text-muted">{d.field}</span>
                            {d.before !== undefined && <span className="break-words text-t-bad line-through">{d.before}</span>}
                            <span className={`min-w-0 whitespace-pre-wrap break-words ${d.before !== undefined ? 'font-semibold text-t-good' : 'text-text'}`}>{d.after}</span>
                            {settled && <span className="text-xs">{c.status === 'applied' ? 'Applied' : 'Discarded'}</span>}
                            {c.message && c.status !== 'applied' && <span className="w-full text-xs font-semibold">{c.message}</span>}
                            <span className="ms-auto flex gap-1">
                                {c.status === 'proposed' && (
                                    <button type="button" disabled={busy} className={`${button} bg-primary text-on-primary hover:opacity-90`} onClick={() => onApply(c)}>
                                        Apply
                                    </button>
                                )}
                                {(c.status === 'proposed' || c.status === 'conflict') && (
                                    <button type="button" disabled={busy} className={`${button} text-text hover:bg-card-2`} onClick={() => onDiscard(c)}>
                                        Discard
                                    </button>
                                )}
                            </span>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
