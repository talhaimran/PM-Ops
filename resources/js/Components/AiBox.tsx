import { isActive } from '@/agentRun';
import AgentRunPanel from '@/Components/AgentRunPanel';
import ApprovalCard from '@/Components/ApprovalCard';
import AskAgent, { AskPreset } from '@/Components/AskAgent';
import { Issue } from '@/types';
import { useLiveReload } from '@/useLiveReload';
import { usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { useState } from 'react';

/** Re-reads the issue while a run is queued or running (live on user.{id}, else the 30 s poll). Mounted only then. */
function LiveRun({ full }: { full: boolean }) {
    useLiveReload(`user.${usePage().props.auth.user.id}`, [full ? 'issue' : 'peek']);
    return null;
}

/**
 * The AI box on the issue page and in the peek drawer (spec 5.1): pending approval requests, your latest agent run with
 * its proposed change set, and "Ask agent" (Mode C; hidden without a provider). Shows nothing when there is nothing to
 * show; never a summary.
 */
export default function AiBox({ issue, full = false }: { issue: Issue; full?: boolean }) {
    const [asking, setAsking] = useState<AskPreset | null>(null);
    const approvals = issue.pending_approvals ?? [];
    const { run, ask } = issue.ai ?? { run: null, ask: null };
    if (!approvals.length && !run && !ask) return null;

    return (
        <div className="space-y-4">
            {approvals.length > 0 && (
                <section aria-labelledby="ai-box-approvals" className="space-y-2">
                    <h2 id="ai-box-approvals" className="text-sm font-semibold text-muted">
                        Waiting for approval
                    </h2>
                    {approvals.map((a) => (
                        <ApprovalCard key={a.id} approval={a} canDecide={!!a.can_decide} />
                    ))}
                </section>
            )}
            {(run || ask) && (
                <section aria-labelledby="ai-box-agents" className="space-y-2">
                    <h2 id="ai-box-agents" className="text-sm font-semibold text-muted">
                        Agents
                    </h2>
                    {run && (
                        <>
                            {isActive(run) && <LiveRun full={full} />}
                            {/* B-R8: the same agent, role and instruction again, or the Ask form when that agent isn't one of yours any more. */}
                            <AgentRunPanel
                                run={run}
                                issueKey={issue.key}
                                canRepeat={!!ask?.agents.some((a) => a.id === run.agent_id)}
                                onAsk={() => setAsking({ agent: run.agent_id, role: run.role_id, instruction: run.instruction })}
                            />
                        </>
                    )}
                    {ask &&
                        (asking ? (
                            <AskAgent issueKey={issue.key} ask={ask} preset={asking} onClose={() => setAsking(null)} />
                        ) : (
                            <button type="button" className="inline-flex h-control-h-sm items-center gap-1.5 rounded-ctl border border-line-2 bg-card px-3 text-sm text-text hover:bg-card-2" onClick={() => setAsking({})}>
                                <Sparkles className="h-4 w-4" aria-hidden="true" />
                                Ask agent
                            </button>
                        ))}
                </section>
            )}
        </div>
    );
}
