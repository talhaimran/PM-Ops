import EmptyState from '@/Components/EmptyState';
import InputError from '@/Components/InputError';
import { selectClass } from '@/Components/IssueFormModal';
import IssueTypeIcon from '@/Components/IssueTypeIcon';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusLozenge from '@/Components/StatusLozenge';
import TextInput from '@/Components/TextInput';
import { issueUrl } from '@/issueUrl';
import { Issue } from '@/types';
import { Link, router, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';

// Spec 7.5, as read from this issue: "WEB-12 blocks WEB-15", "WEB-15 is blocked by WEB-12".
const types = ['blocks', 'is blocked by', 'precedes', 'follows', 'relates to', 'duplicates', 'is duplicated by', 'clones', 'is cloned by'];

const label = (type: string) => type.charAt(0).toUpperCase() + type.slice(1);

/** The issue's links grouped by type, with add and remove for contributors. Removing or adding offers Undo (2-R10). */
export default function IssueLinks({ issue, canEdit }: { issue: Issue; canEdit: boolean }) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ type: 'blocks', to: '' });
    const groups = Object.entries(issue.links ?? {});
    // A key that can't be found comes back under `issue` or `project` (the usual not-found message).
    const errors = form.errors as Record<string, string | undefined>;

    const remove = (type: string, to: string) =>
        router.delete(route('issues.links.destroy', issue.key), { data: { type, to }, preserveScroll: true });

    return (
        <section>
            <div className="mb-2 flex items-center justify-between gap-2">
                <h2 className="font-semibold">Links</h2>
                {canEdit && !adding && <SecondaryButton onClick={() => setAdding(true)}>+ Add link</SecondaryButton>}
            </div>

            {adding && (
                <form
                    className="mb-3 space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('issues.links.store', issue.key), {
                            preserveScroll: true,
                            preserveState: true,
                            onSuccess: () => {
                                form.reset();
                                setAdding(false);
                            },
                        });
                    }}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted">{issue.key}</span>
                        <select aria-label="Link type" className={`${selectClass} !h-control-h w-auto`} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            {types.map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </select>
                        <TextInput
                            aria-label="Issue key"
                            className="h-control-h w-36"
                            placeholder={`e.g. ${issue.project}-1`}
                            value={form.data.to}
                            onChange={(e) => form.setData('to', e.target.value)}
                            onKeyDown={(e) => e.key === 'Escape' && setAdding(false)}
                            isFocused
                            required
                        />
                        <PrimaryButton disabled={form.processing || !form.data.to.trim()}>Link</PrimaryButton>
                        <SecondaryButton
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setAdding(false);
                            }}
                        >
                            Cancel
                        </SecondaryButton>
                    </div>
                    <InputError message={errors.type ?? errors.to ?? errors.issue ?? errors.project} />
                </form>
            )}

            {groups.length === 0 ? (
                !adding && <EmptyState compact title="No links yet." hint={canEdit ? 'Link issues that block, follow or relate to this one.' : undefined} />
            ) : (
                <div className="space-y-3">
                    {groups.map(([type, items]) => (
                        <div key={type}>
                            <h3 className="mb-1 text-xs font-semibold text-muted">{label(type)}</h3>
                            <ul className="divide-y divide-line rounded-ctl border border-line">
                                {items.map((l) => (
                                    <li key={l.key} className="flex items-center hover:bg-card-2">
                                        <Link href={issueUrl(l.key)} className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2">
                                            <IssueTypeIcon type={l.type ?? 'task'} />
                                            <span className="shrink-0 text-muted">{l.key}</span>
                                            <span className={`min-w-0 flex-1 truncate ${l.category === 'done' ? 'text-muted line-through' : ''}`}>{l.title}</span>
                                            {l.status && l.category && <StatusLozenge status={l.status} category={l.category} color={l.status_color} />}
                                        </Link>
                                        {canEdit && (
                                            <button
                                                type="button"
                                                aria-label={`Remove link: ${type} ${l.key}`}
                                                title="Remove link"
                                                className="me-1 flex h-control-h-sm w-control-h-sm shrink-0 items-center justify-center rounded-sm text-muted hover:bg-well hover:text-text"
                                                onClick={() => remove(type, l.key)}
                                            >
                                                <X className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
