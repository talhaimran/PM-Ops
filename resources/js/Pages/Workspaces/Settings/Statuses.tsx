import Dropdown from '@/Components/Dropdown';
import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import { selectClass } from '@/Components/IssueFormModal';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusLozenge, { swatchClass } from '@/Components/StatusLozenge';
import TextInput from '@/Components/TextInput';
import WarningConfirm from '@/Components/WarningConfirm';
import WorkspaceSettingsHeader from '@/Components/WorkspaceSettingsHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { StatusCategory } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Ellipsis, Tags } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { firstError } from './statusErrors';

interface LibraryStatus {
    id: number;
    name: string;
    category: StatusCategory;
    color: string | null;
    projects_count: number;
    issues_count: number;
}

type Dialog = { kind: 'rename' | 'colour' | 'merge' | 'delete'; status: LibraryStatus } | null;

const categoryLabel: Record<StatusCategory, string> = { to_do: 'To do', in_progress: 'In progress', done: 'Done' };
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

/** Spec 7.4: the workspace's status library. Statuses are created from a project's column editor (1-R12), so there is no Create here. */
export default function Statuses({ statuses, palette }: { statuses: LibraryStatus[]; palette: string[] }) {
    const [dialog, setDialog] = useState<Dialog>(null);
    const [name, setName] = useState('');
    const [into, setInto] = useState('');
    const [color, setColor] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    // The last refusal, cleared whenever a dialog opens or closes so it never shows in another one.
    const [error, setError] = useState<string | undefined>();

    const open = (kind: NonNullable<Dialog>['kind'], status: LibraryStatus) => {
        setName(status.name);
        setColor(status.color);
        setInto(statuses.find((o) => o.id !== status.id && o.category === status.category)?.name ?? '');
        setError(undefined);
        setDialog({ kind, status });
    };
    const close = () => {
        setError(undefined);
        setDialog(null);
    };
    const send = (method: 'patch' | 'post' | 'delete', url: string, data: Record<string, unknown> = {}) =>
        router.visit(url, {
            method,
            data: data as never,
            // A non-GET visit remounts the page by default, which drops the open dialog before a refusal can show in it.
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: close,
            onError: (e) => setError(firstError(e)),
        });

    const status = dialog?.status;
    const mergeTargets = status ? statuses.filter((o) => o.id !== status.id && o.category === status.category) : [];
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (status) send('patch', route('statuses.update', status.id), dialog?.kind === 'rename' ? { name } : { color });
    };

    return (
        <AuthenticatedLayout header={<WorkspaceSettingsHeader />}>
            <Head title="Statuses" />

            <div className="max-w-4xl space-y-6 px-4 pb-10 md:px-8">
                <section className="rounded-card bg-card p-5 shadow sm:p-6">
                    <h2 className="text-lg text-text">Statuses</h2>
                    <p className="mt-1 text-sm text-muted">
                        Every project maps these to its board columns. A category is fixed once a status exists; add statuses from a project's Board columns settings.
                    </p>
                    {!dialog && <InputError className="mt-3" message={error} />}

                    {statuses.length === 0 ? (
                        <div className="mt-4">
                            <EmptyState icon={Tags} title="No statuses yet." hint="They are created when a project adds a column." />
                        </div>
                    ) : (
                        <ul className="mt-3 divide-y divide-line">
                            {statuses.map((s) => (
                                <li key={s.id} className="flex items-start gap-x-4 py-3">
                                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1">
                                        <div className="min-w-0 flex-1 basis-48">
                                            <StatusLozenge status={s.name} category={s.category} color={s.color} />
                                        </div>
                                        <span className="w-24 text-sm text-muted">{categoryLabel[s.category]}</span>
                                        <span className="w-52 text-sm text-muted">
                                            Used by {plural(s.projects_count, 'project')}, {plural(s.issues_count, 'issue')}
                                        </span>
                                    </div>
                                    {/* Outside the wrapping group, so the right-aligned menu always opens on screen. */}
                                    <Dropdown>
                                        <Dropdown.Trigger>
                                            <button type="button" aria-label={`Actions for ${s.name}`} className="flex h-8 w-8 items-center justify-center rounded-ctl text-muted hover:bg-card-2">
                                                <Ellipsis className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        </Dropdown.Trigger>
                                        <Dropdown.Content>
                                            {(
                                                [
                                                    ['rename', 'Rename'],
                                                    ['colour', 'Colour'],
                                                    ['merge', 'Merge into…'],
                                                ] as const
                                            ).map(([kind, label]) => (
                                                <button key={kind} type="button" className="block w-full px-4 py-2 text-start hover:bg-card-2" onClick={() => open(kind, s)}>
                                                    {label}
                                                </button>
                                            ))}
                                            <button type="button" className="block w-full border-l-2 border-transparent px-4 py-2 text-start text-t-bad hover:border-c-bad hover:bg-card-2" onClick={() => open('delete', s)}>
                                                Delete
                                            </button>
                                        </Dropdown.Content>
                                    </Dropdown>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <Modal show={dialog?.kind === 'rename' || dialog?.kind === 'colour'} onClose={close} maxWidth="md">
                <form onSubmit={submit} className="space-y-4 p-6">
                    <h2 className="text-heading-2xl text-text">{dialog?.kind === 'rename' ? `Rename ${status?.name}` : `Colour of ${status?.name}`}</h2>
                    {dialog?.kind === 'rename' ? (
                        <TextInput aria-label="Status name" className="block w-full" value={name} onChange={(e) => setName(e.target.value)} isFocused required maxLength={100} />
                    ) : (
                        <fieldset className="flex flex-wrap gap-2">
                            <legend className="sr-only">Colour</legend>
                            {[null, ...palette].map((c) => (
                                <label key={c ?? 'default'} className={`flex h-control-h cursor-pointer items-center gap-2 rounded-ctl border px-3 text-sm ${color === c ? 'border-primary' : 'border-line-2'}`}>
                                    <input type="radio" name="color" className="sr-only" checked={color === c} onChange={() => setColor(c)} />
                                    <span aria-hidden="true" className={`h-2.5 w-2.5 rounded-pill ${c ? swatchClass(c) : 'bg-muted'}`} />
                                    {c ?? `Category colour`}
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <InputError message={error} />
                    <div className="flex justify-end gap-2">
                        <SecondaryButton onClick={close}>Cancel</SecondaryButton>
                        <PrimaryButton disabled={processing}>Save</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <WarningConfirm
                open={dialog?.kind === 'merge'}
                title={`Merge ${status?.name} into…`}
                warnings={
                    status && into
                        ? [
                              { text: `Merge ${status.name} into ${into}: ${plural(status.issues_count, 'issue')} in ${plural(status.projects_count, 'project')}`, reason: `${status.name} is deleted afterwards. This can't be undone.` },
                          ]
                        : [{ text: `No other ${status ? categoryLabel[status.category] : ''} status to merge into.`, reason: 'Statuses merge only within the same category.' }]
                }
                confirmLabel="Merge"
                processing={processing || !into}
                onCancel={close}
                onConfirm={() => status && into && send('post', route('statuses.merge', status.id), { into })}
            >
                {mergeTargets.length > 0 && (
                    <select aria-label="Merge into" className={`${selectClass} block w-full`} value={into} onChange={(e) => setInto(e.target.value)}>
                        {mergeTargets.map((o) => (
                            <option key={o.id}>{o.name}</option>
                        ))}
                    </select>
                )}
                <InputError message={error} />
            </WarningConfirm>

            <WarningConfirm
                open={dialog?.kind === 'delete'}
                title={`Delete ${status?.name}?`}
                warnings={[
                    status && status.projects_count + status.issues_count > 0
                        ? { text: `Used by ${plural(status.projects_count, 'project')}, ${plural(status.issues_count, 'issue')}.`, reason: 'Merge it into another status, or remove it from its columns and move its issues first.' }
                        : { text: 'No project or issue uses it.' },
                    { text: "A deleted status can't be brought back." },
                ]}
                confirmLabel="Delete status"
                processing={processing}
                onCancel={close}
                onConfirm={() => status && send('delete', route('statuses.destroy', status.id))}
            >
                <InputError message={error} />
            </WarningConfirm>
        </AuthenticatedLayout>
    );
}
