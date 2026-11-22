import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { selectClass } from '@/Components/IssueFormModal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import WarningConfirm from '@/Components/WarningConfirm';
import WorkspaceSettingsHeader from '@/Components/WorkspaceSettingsHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/** Spec 8 (A5): the workspace's AI connection and the Mode C switch. The key is only ever sent to the server, never back. */
interface Connection {
    source: 'env' | 'workspace';
    provider: string;
    model: string;
    endpoint: string | null;
    has_key: boolean;
    cap: number | null;
    used: number;
}

export default function Ai({ connection, providers, mode_c }: { connection: Connection | null; providers: { value: string; label: string }[]; mode_c: boolean }) {
    const fromServer = connection?.source === 'env';
    const form = useForm({
        provider: connection?.provider ?? providers[0].value,
        model: connection?.model ?? '',
        endpoint: connection?.endpoint ?? '',
        api_key: '',
    });
    const cap = useForm({ monthly_token_cap: connection?.cap?.toString() ?? '' });
    const modeC = useForm({ mode_c_enabled: mode_c });
    const remove = useForm({});
    const [removing, setRemoving] = useState(false);
    const compatible = form.data.provider === 'openai_compatible';
    const moved = connection && !fromServer && (form.data.provider !== connection.provider || form.data.endpoint !== (connection.endpoint ?? ''));
    const label = providers.find((p) => p.value === connection?.provider)?.label ?? connection?.provider;

    return (
        <AuthenticatedLayout header={<WorkspaceSettingsHeader />}>
            <Head title="AI" />

            <div className="max-w-3xl space-y-6 px-4 pb-10 md:px-8">
                <section className="rounded-card bg-card p-5 shadow sm:p-6">
                    <h2 className="text-lg text-text">AI provider</h2>
                    <p className="mt-1 text-sm text-muted">
                        One connection serves Mode C, plain-English search and AI release notes. Without it those stay hidden; agents using the API or MCP still work.
                    </p>

                    {fromServer && connection ? (
                        <div className="mt-4 space-y-3">
                            <p role="status" className="rounded-card tint-info p-3 text-sm">
                                Configured by your server. Change PM_AI_PROVIDER and the other PM_AI settings in its environment to edit this.
                            </p>
                            <dl className="grid grid-cols-[8rem_1fr] gap-y-2 text-md">
                                <dt className="text-muted">Provider</dt>
                                <dd className="text-text">{label}</dd>
                                <dt className="text-muted">Model</dt>
                                <dd className="break-all text-text">{connection.model}</dd>
                                <dt className="text-muted">Endpoint</dt>
                                <dd className="break-all text-text">{connection.endpoint ?? 'Provider default'}</dd>
                                <dt className="text-muted">API key</dt>
                                <dd className="text-text">{connection.has_key ? 'Set on your server' : 'Not set'}</dd>
                            </dl>
                        </div>
                    ) : (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.patch(route('settings.ai.update'), { preserveScroll: true, onSuccess: () => form.reset('api_key') });
                            }}
                            className="mt-4 space-y-4"
                        >
                            {!connection && <p className="text-sm text-muted">No AI provider yet. Pick one and add its key to turn these on.</p>}
                            <div>
                                <InputLabel htmlFor="provider" value="Provider" />
                                <select id="provider" className={`${selectClass} mt-1 block w-full`} value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                    {providers.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError className="mt-2" message={form.errors.provider} />
                            </div>
                            <div>
                                <InputLabel htmlFor="model" value="Model" />
                                <TextInput id="model" className="mt-1 block w-full" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} required maxLength={100} />
                                <InputError className="mt-2" message={form.errors.model} />
                            </div>
                            <div>
                                <InputLabel htmlFor="endpoint" value={compatible ? 'Endpoint' : 'Endpoint (optional)'} />
                                <TextInput
                                    id="endpoint"
                                    className="mt-1 block w-full"
                                    value={form.data.endpoint}
                                    onChange={(e) => form.setData('endpoint', e.target.value)}
                                    placeholder="https://…"
                                    required={compatible}
                                    maxLength={255}
                                />
                                <p className="mt-1 text-sm text-muted">Use https://, or http://localhost for a model on this machine.</p>
                                <InputError className="mt-2" message={form.errors.endpoint} />
                            </div>
                            <div>
                                <InputLabel htmlFor="api_key" value={compatible ? 'API key (optional)' : 'API key'} />
                                <TextInput
                                    id="api_key"
                                    name="api_key"
                                    type="password"
                                    autoComplete="new-password"
                                    className="mt-1 block w-full"
                                    value={form.data.api_key}
                                    onChange={(e) => form.setData('api_key', e.target.value)}
                                    placeholder={connection?.has_key && !moved ? 'Leave blank to keep the current key' : ''}
                                    maxLength={500}
                                />
                                <p className="mt-1 text-sm text-muted">
                                    {moved ? 'Changing the provider or endpoint needs its key again. The old key is not sent to the new one.' : 'Stored encrypted. It is never shown again, not even to Owners.'}
                                </p>
                                <InputError className="mt-2" message={form.errors.api_key} />
                            </div>
                            <div className="flex justify-end gap-2">
                                {connection && (
                                    <SecondaryButton onClick={() => setRemoving(true)} disabled={remove.processing}>
                                        Remove provider
                                    </SecondaryButton>
                                )}
                                <PrimaryButton disabled={form.processing}>Save changes</PrimaryButton>
                            </div>
                        </form>
                    )}
                </section>

                {connection && !fromServer && (
                    <section className="rounded-card bg-card p-5 shadow sm:p-6">
                        <h2 className="text-lg text-text">Monthly token cap</h2>
                        <p className="mt-1 text-sm text-muted">{connection.used.toLocaleString()} tokens used this month. Leave it empty for no cap.</p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                cap.patch(route('settings.ai.cap'), { preserveScroll: true });
                            }}
                            className="mt-4 space-y-4"
                        >
                            <div>
                                <InputLabel htmlFor="monthly_token_cap" value="Tokens per month" />
                                <TextInput
                                    id="monthly_token_cap"
                                    type="number"
                                    min={1}
                                    className="mt-1 block w-full sm:w-64"
                                    value={cap.data.monthly_token_cap}
                                    onChange={(e) => cap.setData('monthly_token_cap', e.target.value)}
                                />
                                <InputError className="mt-2" message={cap.errors.monthly_token_cap} />
                            </div>
                            <div className="flex justify-end">
                                <PrimaryButton disabled={cap.processing || !cap.isDirty}>Save changes</PrimaryButton>
                            </div>
                        </form>
                    </section>
                )}

                <section className="rounded-card bg-card p-5 shadow sm:p-6">
                    <h2 className="text-lg text-text">Mode C</h2>
                    <p className="mt-1 text-sm text-muted">Mode C sends issue content to the chosen provider.</p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            modeC.patch(route('settings.ai.mode-c'), { preserveScroll: true });
                        }}
                        className="mt-4 space-y-4"
                    >
                        <label className="flex min-h-control-h items-center gap-3">
                            <Checkbox checked={modeC.data.mode_c_enabled} onChange={(e) => modeC.setData('mode_c_enabled', e.target.checked)} />
                            <span className="text-md text-text">
                                Allow Mode C in this workspace
                                <span className="block text-sm text-muted">
                                    Turned off, no project runs Mode C. Project Admins can also turn it off for one project. It needs a provider above to run.
                                </span>
                            </span>
                        </label>
                        <InputError message={modeC.errors.mode_c_enabled} />
                        <div className="flex justify-end">
                            <PrimaryButton disabled={modeC.processing || !modeC.isDirty}>Save changes</PrimaryButton>
                        </div>
                    </form>
                </section>
            </div>

            <WarningConfirm
                open={removing}
                title="Remove the AI provider?"
                warnings={[
                    { text: 'The stored key is deleted.' },
                    { text: 'Mode C, plain-English search and AI release notes are hidden until a provider is set again.' },
                ]}
                confirmLabel="Remove provider"
                processing={remove.processing}
                onCancel={() => setRemoving(false)}
                onConfirm={() => remove.delete(route('settings.ai.destroy'), { preserveScroll: true, onSuccess: () => setRemoving(false) })}
            />
        </AuthenticatedLayout>
    );
}
