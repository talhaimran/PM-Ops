import { ago, ConflictFlash, diffWords, fieldLabel, show } from '@/conflict';
import { DialogTitle } from '@headlessui/react';
import { router, usePage } from '@inertiajs/react';
import { ArrowRightLeft } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Modal from './Modal';
import PrimaryButton from './PrimaryButton';

const ghost = 'flex h-control-h items-center rounded-ctl px-3 text-md text-text hover:bg-card-2';

/**
 * Design system conflict dialog (spec 7.11): a save was refused because someone (a person or an agent) changed the
 * issue or comment since it was read. Nothing is lost: "Keep theirs" copies yours to the clipboard first, and
 * "Save mine over theirs" resends yours with the current version (logged as an overwrite). Changed words are struck
 * through or bold, never told by colour alone; the sides stack under 760px.
 */
export default function ConflictDialog() {
    const { flash } = usePage().props as { flash?: { conflict?: ConflictFlash | null } };
    const [shown, setShown] = useState<ConflictFlash | null>(null);
    const [copied, setCopied] = useState(false);
    const keep = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!flash?.conflict) return;
        setShown(flash.conflict);
        setCopied(false);
    }, [flash?.conflict]);

    const fields = Object.keys(shown?.yours ?? {});
    const mine = () => (fields.length === 1 ? show(shown!.yours[fields[0]]) : fields.map((f) => `${fieldLabel(f)}: ${show(shown!.yours[f])}`).join('\n'));
    const copy = () => navigator.clipboard?.writeText(mine()).then(() => setCopied(true), () => {});
    // Reload without local state, so open editors close and show their version.
    const keepTheirs = () => {
        copy();
        setShown(null);
        router.visit(window.location.href, { preserveScroll: true, preserveState: false });
    };
    const saveMine = () =>
        router.patch(shown!.url, { ...shown!.yours, version: shown!.version, overwrite: true } as never, {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => setShown(null),
        });

    const side = (theirs: boolean) =>
        fields.map((f) => {
            const d = diffWords(show(shown!.yours[f]), show(shown!.theirs[f]));
            return (
                <p key={f} className="break-words text-md">
                    {fields.length > 1 && <span className="text-muted">{fieldLabel(f)}: </span>}
                    {d.head}
                    {!theirs && d.theirs && <del className="text-t-bad line-through">{d.theirs}</del>}
                    {!theirs && d.theirs && d.mine && ' '}
                    {(theirs ? d.theirs : d.mine) && <ins className="font-semibold text-t-good no-underline">{theirs ? d.theirs : d.mine}</ins>}
                    {d.tail}
                </p>
            );
        });

    const when = shown?.at ? ago(shown.at) : null;

    return (
        <Modal show={shown !== null} onClose={() => setShown(null)} maxWidth="2xl" initialFocus={keep}>
            {shown && (
                <div className="space-y-4 p-6">
                    <div className="flex items-start gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-tile tint-cobalt">
                            <ArrowRightLeft className="h-5 w-5" aria-hidden="true" />
                        </span>
                        <DialogTitle className="pt-1.5 text-heading-2xl text-text">{shown.key} changed while you were editing</DialogTitle>
                    </div>
                    <p className="text-md text-muted">
                        Changed{shown.by ? ` by ${shown.by}` : ''}
                        {when ? ` ${when}` : ''}. Review changes?
                    </p>
                    <div className="grid gap-3 md:grid-cols-2">
                        <section aria-labelledby="conflict-yours" className="grid content-start gap-1.5 rounded-tile border border-line px-3.5 py-3">
                            <h3 id="conflict-yours" className="text-xs font-semibold text-muted">
                                Your version
                            </h3>
                            {side(false)}
                        </section>
                        <section aria-labelledby="conflict-theirs" className="grid content-start gap-1.5 rounded-tile border border-c-cobalt px-3.5 py-3">
                            <h3 id="conflict-theirs" className="text-xs font-semibold text-muted">
                                Their version{shown.by ? ` · ${shown.by}` : ''}
                                {when ? `, ${when}` : ''}
                            </h3>
                            {side(true)}
                        </section>
                    </div>
                    <div className="flex flex-wrap justify-end gap-2">
                        <button ref={keep} type="button" className={ghost} onClick={keepTheirs}>
                            Keep theirs
                        </button>
                        <button type="button" className={ghost} onClick={copy}>
                            {copied ? 'Copied' : 'Copy mine'}
                        </button>
                        <PrimaryButton type="button" onClick={saveMine}>
                            Save mine over theirs
                        </PrimaryButton>
                    </div>
                </div>
            )}
        </Modal>
    );
}
