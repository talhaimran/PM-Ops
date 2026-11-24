import Avatar from '@/Components/Avatar';
import InputError from '@/Components/InputError';
import { selectClass } from '@/Components/IssueFormModal';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Issue, IssueOptions } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff, X } from 'lucide-react';
import { useState } from 'react';

/** Spec 7.10: the Watch toggle with its count; the count opens the list. Members add a teammate, project admins remove one. */
export default function Watchers({ issue, options, canAdd, canRemove }: { issue: Issue; options: IssueOptions; canAdd: boolean; canRemove: boolean }) {
    const [open, setOpen] = useState(false);
    const me = usePage().props.auth.user;
    const watching = !!issue.watching;
    const watchers = issue.watchers ?? [];
    const add = useForm({ user: '' });
    const errors = add.errors as Record<string, string | undefined>;
    const choices = options.members.filter((m) => !watchers.some((w) => w.email === m.email));
    const url = route(watching ? 'issues.watchers.destroy' : 'issues.watchers.store', issue.key);
    const toggle = () => router.visit(url, { method: watching ? 'delete' : 'post', preserveScroll: true, preserveState: true });

    return (
        <div className="flex items-center gap-2">
            <button
                type="button"
                aria-pressed={watching}
                onClick={toggle}
                className="inline-flex h-control-h-sm items-center gap-1.5 rounded-ctl border border-line-2 px-2.5 text-sm text-text hover:bg-card-2"
            >
                {watching ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
                {watching ? 'Stop watching' : 'Watch'}
            </button>
            <button type="button" onClick={() => setOpen(true)} className="inline-flex h-control-h-sm items-center rounded-ctl px-2 text-sm text-muted hover:bg-card-2 hover:text-text" aria-label={`${issue.watchers_count ?? 0} watchers`}>
                {issue.watchers_count ?? 0}
            </button>

            <Modal show={open} onClose={() => setOpen(false)} maxWidth="md">
                <div className="space-y-4 p-6">
                    <h2 className="text-heading-xl text-text">Watching {issue.key}</h2>
                    <ul className="space-y-2">
                        {watchers.length === 0 && <li className="text-muted">Nobody is watching yet.</li>}
                        {watchers.map((w) => (
                            <li key={w.id} className="flex items-center gap-2">
                                <Avatar name={w.name} email={w.email} />
                                <span className="min-w-0 flex-1 truncate text-text">{w.name}</span>
                                {canRemove && w.id !== me.id && (
                                    <button
                                        type="button"
                                        aria-label={`Remove ${w.name}`}
                                        className="flex h-8 w-8 items-center justify-center rounded-ctl text-muted hover:bg-card-2"
                                        onClick={() => router.delete(route('issues.watchers.destroy', issue.key), { data: { user: w.email }, preserveScroll: true, preserveState: true })}
                                    >
                                        <X className="h-4 w-4" aria-hidden="true" />
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                    {canAdd && (
                        <form
                            className="space-y-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                add.post(route('issues.watchers.store', issue.key), { preserveScroll: true, preserveState: true, onSuccess: () => add.reset() });
                            }}
                        >
                            <div className="flex gap-2">
                                <select aria-label="Add watcher" className={`${selectClass} !h-control-h min-w-0 flex-1`} value={add.data.user} onChange={(e) => add.setData('user', e.target.value)}>
                                    <option value="">Add watcher…</option>
                                    {choices.map((m) => (
                                        <option key={m.email} value={m.email}>
                                            {m.name}
                                        </option>
                                    ))}
                                </select>
                                <PrimaryButton disabled={add.processing || !add.data.user}>Add</PrimaryButton>
                            </div>
                            <InputError message={errors.user} />
                        </form>
                    )}
                    <div className="flex justify-end">
                        <SecondaryButton onClick={() => setOpen(false)}>Close</SecondaryButton>
                    </div>
                </div>
            </Modal>
        </div>
    );
}
