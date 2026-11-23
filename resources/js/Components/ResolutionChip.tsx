import { lozengeClass } from '@/Components/StatusLozenge';
import InputError from '@/Components/InputError';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Resolution } from '@/types';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { router, usePage } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

export const resolutionLabels: Record<Resolution, string> = {
    done: 'Done',
    wont_do: "Won't do",
    duplicate: 'Duplicate',
    cannot_reproduce: 'Cannot reproduce',
};
export const resolutions = Object.keys(resolutionLabels) as Resolution[];

/** Change an issue's resolution (and status, for "Close as…"); the server rule is IssueService::update. */
export function resolveIssue(key: string, data: Record<string, unknown>, onSuccess?: () => void) {
    router.patch(route('issues.update', key), data as never, { preserveScroll: true, preserveState: true, onSuccess });
}

/** Duplicate asks for the original issue's key; the server's answer ("not found", "itself") shows under the field. */
export function DuplicateOfModal({ show, projectKey, onClose, onSubmit }: { show: boolean; projectKey: string; onClose: () => void; onSubmit: (original: string) => void }) {
    const error = (usePage().props.errors as Record<string, string>).duplicate_of;
    const [original, setOriginal] = useState('');
    const submit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit(original.trim());
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="space-y-4 p-6">
                <h2 className="text-heading-xl text-text">Close as duplicate</h2>
                <div>
                    <label htmlFor="duplicate-of" className="mb-1 block text-md text-text">
                        Original issue
                    </label>
                    <TextInput id="duplicate-of" className="w-full" placeholder={`e.g. ${projectKey}-3`} value={original} onChange={(e) => setOriginal(e.target.value)} isFocused required />
                    <InputError className="mt-1" message={error} />
                </div>
                <div className="flex justify-end gap-2">
                    <SecondaryButton onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton>Close as duplicate</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

/**
 * "Resolved: Done ▾" (spec 7.4): the resolution of a Done-category issue, with a menu to change it.
 * Read-only text when the viewer can't edit. Shown on a board card for as long as the move's Undo toast.
 */
export default function ResolutionChip({ issueKey, resolution, editable, onBusy }: { issueKey: string; resolution: Resolution; editable: boolean; onBusy?: (busy: boolean) => void }) {
    const [asking, setAsking] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    // Tells the board to keep this chip while the menu or the dialog is open, however long that takes.
    useEffect(() => onBusy?.(menuOpen || asking), [menuOpen, asking, onBusy]);
    const label = `Resolved: ${resolutionLabels[resolution]}`;
    const chip = `inline-flex h-pill-h items-center gap-1 rounded-pill px-2.5 text-xs ${lozengeClass('done')}`;

    if (!editable) return <span className={chip}>{label}</span>;

    return (
        // Stop the board's drag handlers: this sits inside a draggable card.
        <div onPointerDown={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
            <Menu>
                {({ open }) => (
                    <>
                <OpenState open={open} onChange={setMenuOpen} />
                <MenuButton className={chip}>
                    {label}
                    <ChevronDown className="h-3.5 w-3.5" aria-hidden="true" />
                </MenuButton>
                <MenuItems anchor="bottom start" className="z-50 min-w-44 overflow-hidden rounded-ctl border border-line bg-card py-1 text-md shadow-pop">
                    {resolutions.map((r) => (
                        <MenuItem key={r}>
                            <button
                                type="button"
                                className="flex w-full items-center gap-2 px-4 py-2 text-start text-text data-[focus]:bg-card-2"
                                onClick={() => (r === 'duplicate' ? setAsking(true) : r !== resolution && resolveIssue(issueKey, { resolution: r }))}
                            >
                                <span className="flex-1">{resolutionLabels[r]}</span>
                                {r === resolution && <Check className="h-4 w-4" aria-label="Current" />}
                            </button>
                        </MenuItem>
                    ))}
                </MenuItems>
                    </>
                )}
            </Menu>
            <DuplicateOfModal show={asking} projectKey={issueKey.split('-')[0]} onClose={() => setAsking(false)} onSubmit={(original) => resolveIssue(issueKey, { resolution: 'duplicate', duplicate_of: original }, () => setAsking(false))} />
        </div>
    );
}

/** Headless UI only gives `open` to a render prop; this hands it up as state. */
function OpenState({ open, onChange }: { open: boolean; onChange: (open: boolean) => void }) {
    useEffect(() => onChange(open), [open, onChange]);
    return null;
}
