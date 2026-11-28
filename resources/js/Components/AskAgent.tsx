import { selectClass } from '@/Components/IssueFormModal';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { AskAgentData } from '@/types';
import { Link, useForm } from '@inertiajs/react';

export interface AskPreset {
    agent?: number;
    role?: number | null;
    instruction?: string | null;
}

/** "Ask agent" (spec 8): one of your own active agents, a role or a typed instruction. Progress shows in the run panel. */
export default function AskAgent({ issueKey, ask, preset, onClose }: { issueKey: string; ask: AskAgentData; preset: AskPreset; onClose: () => void }) {
    const form = useForm({ agent: String(preset.agent ?? ask.agents[0]?.id ?? ''), role: String(preset.role ?? ''), instruction: preset.instruction ?? '' });

    if (!ask.agents.length) {
        return (
            <p className="text-sm text-muted">
                Create an agent in{' '}
                <Link href={route('agents.index')} className="text-primary underline">
                    Settings → Agents
                </Link>{' '}
                to ask it for help.
            </p>
        );
    }

    return (
        <form
            className="space-y-2 rounded-card bg-card-2 p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(route('runs.store', issueKey), { preserveScroll: true, preserveState: true, onSuccess: onClose });
            }}
        >
            <div className="flex flex-wrap gap-2">
                <select aria-label="Agent" className={selectClass} value={form.data.agent} onChange={(e) => form.setData('agent', e.target.value)}>
                    {ask.agents.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.label}
                        </option>
                    ))}
                </select>
                <select aria-label="Role" className={selectClass} value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                    <option value="">Type an instruction</option>
                    {ask.roles.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.name}
                        </option>
                    ))}
                </select>
            </div>
            <textarea
                aria-label={form.data.role ? 'Extra instructions (optional)' : 'Instruction'}
                placeholder={form.data.role ? 'Extra instructions (optional)' : 'What should the agent do?'}
                maxLength={4000}
                rows={3}
                required={!form.data.role}
                className={`${selectClass} h-auto w-full py-2`}
                value={form.data.instruction}
                onChange={(e) => form.setData('instruction', e.target.value)}
            />
            <InputError message={form.errors.agent ?? form.errors.role ?? form.errors.instruction} />
            <div className="flex justify-end gap-2">
                <SecondaryButton onClick={onClose}>Cancel</SecondaryButton>
                <PrimaryButton disabled={form.processing}>Ask</PrimaryButton>
            </div>
        </form>
    );
}
