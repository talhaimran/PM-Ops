import Checkbox from '@/Components/Checkbox';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

/** Spec 7.9: clone this issue; sub-tasks and links are opt-in. Goes to the new issue, whose Undo deletes it. */
export default function CloneModal({ show, issueKey, onClose }: { show: boolean; issueKey: string; onClose: () => void }) {
    const [subtasks, setSubtasks] = useState(false);
    const [links, setLinks] = useState(false);
    const [busy, setBusy] = useState(false);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.post(route('issues.clone', issueKey), { subtasks, links }, { onStart: () => setBusy(true), onFinish: () => { setBusy(false); onClose(); } });
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="space-y-4 p-6">
                <h2 className="text-heading-xl text-text">Clone {issueKey}</h2>
                <label className="flex items-center gap-2 text-md text-text">
                    <Checkbox checked={subtasks} onChange={(e) => setSubtasks(e.target.checked)} />
                    Include sub-tasks
                </label>
                <label className="flex items-center gap-2 text-md text-text">
                    <Checkbox checked={links} onChange={(e) => setLinks(e.target.checked)} />
                    Include links
                </label>
                <div className="flex justify-end gap-2">
                    <SecondaryButton onClick={onClose}>Cancel</SecondaryButton>
                    <PrimaryButton disabled={busy}>Clone</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
