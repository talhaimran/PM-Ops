import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import { FormEvent } from 'react';

type Level = 'read' | 'write' | 'delete';
const levelLabels: Record<Level, string> = { read: 'Read only', write: 'Read and write', delete: 'Read, write and delete' };

/**
 * A-D3 consent screen: an MCP client asks to connect; the person picks which of their agents it acts as, or makes a
 * new one. The client name comes from the client itself, so it is only ever rendered as text.
 */
export default function Authorize({
    client,
    clientNote,
    redirectTo,
    workspace,
    agents,
    newAgent,
    authToken,
}: {
    client: string;
    clientNote: string;
    redirectTo: string;
    workspace: string | null;
    agents: { id: number; name: string; level: Level }[];
    newAgent: string;
    authToken: string;
}) {
    const canCreate = workspace !== null && !agents.some((a) => a.name === newAgent);
    // Nothing preselected: connecting is a deliberate choice of agent.
    const form = useForm({ agent: '' });
    // A refused choice comes back to a fresh authorization request (new auth token), so read both from the page.
    const errors = usePage().props.errors as Record<string, string>;
    const approve = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, auth_token: authToken }));
        form.post(route('passport.authorizations.approve'));
    };
    const deny = () => router.delete(route('passport.authorizations.deny'), { data: { auth_token: authToken } });
    const choice = (value: string, label: string, hint: string) => (
        <label key={value} className="flex min-h-control-h items-start gap-2 rounded-item px-2 py-1.5 text-sm text-text hover:bg-card-2">
            <input type="radio" name="agent" className="mt-1" checked={form.data.agent === value} onChange={() => form.setData('agent', value)} />
            <span className="min-w-0 break-words">
                {label}
                <span className="block text-muted">{hint}</span>
            </span>
        </label>
    );

    return (
        <GuestLayout>
            <Head title="Connect an MCP client" />

            <div className="flex flex-col items-center gap-3 text-center">
                <span className="flex h-10 w-10 items-center justify-center rounded-tile bg-card-2 text-muted">
                    <Bot className="h-5 w-5" aria-hidden="true" />
                </span>
                <h1 className="break-words text-heading-2xl text-text">Connect {client}</h1>
                <p className="rounded-item tint-amber px-3 py-2 text-sm text-text">
                    {clientNote} You&apos;ll be sent back to <strong className="break-all">{redirectTo}</strong>.
                </p>
                <p className="text-muted">
                    {workspace ? (
                        <>
                            <strong className="text-text">{client}</strong> will act as the agent you pick in <strong className="text-text">{workspace}</strong>. It can do
                            what that agent can, never more than you. Pause or revoke it any time in Settings → Agents.
                        </>
                    ) : (
                        'You are not in any workspace yet, so there is no agent this client could act as.'
                    )}
                </p>
            </div>

            <form onSubmit={approve} className="mt-6">
                {workspace && (
                    <fieldset>
                        <legend className="text-sm font-medium text-text">Act as</legend>
                        <div className="mt-1 space-y-1">
                            {agents.map((a) => choice(String(a.id), a.name, levelLabels[a.level]))}
                            {canCreate && choice('new', `Create ${newAgent}`, `${levelLabels.write}. Counts toward your plan's agents.`)}
                        </div>
                        <InputError className="mt-1" message={errors.agent ?? errors.plan_limit ?? errors.name} />
                    </fieldset>
                )}

                <div className="mt-6 flex flex-wrap items-center justify-end gap-3">
                    <SecondaryButton onClick={deny}>Cancel</SecondaryButton>
                    {workspace && (
                        <PrimaryButton type="submit" disabled={form.processing || form.data.agent === ''}>
                            Connect
                        </PrimaryButton>
                    )}
                </div>
            </form>
        </GuestLayout>
    );
}
