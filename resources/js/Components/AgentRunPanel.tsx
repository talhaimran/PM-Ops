import { isActive, runActions, shortArgs } from '@/agentRun';
import Avatar from '@/Components/Avatar';
import InputError from '@/Components/InputError';
import ChangeSetDiff from '@/Components/ChangeSetDiff';
import { useWarningConfirm } from '@/Components/WarningConfirm';
import { AgentRun, AgentRunStatus } from '@/types';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const button = 'inline-flex h-control-h-sm items-center justify-center rounded-ctl px-3 text-sm transition-colors duration-instant disabled:cursor-not-allowed disabled:opacity-50';
const statusTint: Record<AgentRunStatus, [string, string]> = {
    queued: ['Queued', 'tint-slate'],
    running: ['Running', 'tint-cobalt'],
    finished: ['Finished', 'tint-good'],
    failed: ['Failed', 'tint-bad'],
    cancelled: ['Cancelled', 'tint-slate'],
};

/**
 * Design system agent run panel: who ran, what it read and called (collapsed), and the change set it proposes with
 * Apply all / per-change Apply and Discard. Propose again starts the same run (`canRepeat`) or opens Ask (`onAsk`, B-R8).
 * A refusal (applied meanwhile, issue gone, the month's cap…) shows under the panel.
 */
export default function AgentRunPanel({ run, issueKey, canRepeat, onAsk }: { run: AgentRun; issueKey: string; canRepeat: boolean; onAsk: () => void }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [ask, dialog] = useWarningConfirm();
    const act = runActions(run);
    const [label, tint] = statusTint[run.status];
    const calls = (run.steps ?? []).flatMap((s) => s.calls ?? []);
    const send = (url: string, data: Record<string, unknown> = {}) =>
        router.post(url, data as never, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (setBusy(true), setError(null)),
            onError: (errors) => setError(Object.values(errors)[0] ?? null),
            onFinish: () => setBusy(false),
        });
    const post = (name: string, params: number[]) => send(route(name, params));
    const proposeAgain = () => (canRepeat ? send(route('runs.store', issueKey), { agent: run.agent_id, role: run.role_id, instruction: run.instruction }) : onAsk());

    return (
        <article className="space-y-3 rounded-card border border-line bg-card p-4" aria-label="Agent run">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <Avatar agent name={run.agent} />
                <strong className="font-semibold text-text">{run.agent}</strong>
                <span className="text-muted">{run.role_name ?? 'Instruction'}</span>
                <span className={`rounded-pill px-2 py-0.5 text-xs font-semibold ${tint}`} aria-live="polite">
                    {label}
                </span>
                <span className="ms-auto text-xs text-muted">{(run.tokens_in + run.tokens_out).toLocaleString()} tokens</span>
                {act.cancel && (
                    <button type="button" disabled={busy} className={`${button} border border-line-2 text-text hover:bg-card-2`} onClick={() => post('runs.cancel', [run.id])}>
                        Cancel
                    </button>
                )}
            </div>
            {run.error && <p className="rounded-ctl px-3 py-2 text-sm tint-bad">{run.error}</p>}
            {calls.length > 0 && (
                <details className="text-sm">
                    <summary className="cursor-pointer text-muted">What it read and did</summary>
                    <ol className="mt-2 space-y-1">
                        {calls.map((c, i) => (
                            <li key={i} className="min-w-0 break-words">
                                <code className="rounded-ctl bg-card-2 px-1">{c.tool}</code> <span className="text-muted">{shortArgs(c.args)}</span>
                                {c.error && <span className="ms-1 rounded-ctl px-1 tint-bad">{c.error}</span>}
                            </li>
                        ))}
                    </ol>
                </details>
            )}
            {run.changes.length > 0 && <ChangeSetDiff run={run} busy={busy} onApply={(c) => post('runs.changes.apply', [run.id, c.id])} onDiscard={(c) => post('runs.changes.discard', [run.id, c.id])} />}
            {(act.apply > 0 || act.discard > 0 || act.propose) && !isActive(run) && (
                <div className="flex flex-wrap justify-end gap-2">
                    {act.propose && (
                        <button type="button" disabled={busy} className={`${button} border border-line-2 text-text hover:bg-card-2`} onClick={proposeAgain}>
                            Propose again
                        </button>
                    )}
                    {act.discard > 0 && (
                        <button
                            type="button"
                            disabled={busy}
                            className={`${button} text-text hover:bg-card-2`}
                            onClick={() =>
                                ask({
                                    title: 'Discard all changes?',
                                    warnings: [{ text: `${act.discard} ${act.discard === 1 ? 'change' : 'changes'} will be discarded.`, reason: 'This can’t be undone.' }],
                                    confirmLabel: 'Discard all',
                                    run: () => post('runs.discard', [run.id]),
                                })
                            }
                        >
                            Discard all
                        </button>
                    )}
                    {act.apply > 0 && (
                        <button type="button" disabled={busy} className={`${button} bg-primary text-on-primary hover:opacity-90`} onClick={() => post('runs.apply', [run.id])}>
                            Apply all ({act.apply})
                        </button>
                    )}
                </div>
            )}
            <InputError message={error ?? undefined} />
            {dialog}
        </article>
    );
}

