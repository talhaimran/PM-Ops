import Checkbox from '@/Components/Checkbox';
import WarningConfirm from '@/Components/WarningConfirm';
import { Issue } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface DateShiftFlash {
    key: string;
    shifts: { key: string; start_date: string | null; due_date: string | null; note: string | null }[];
}

const when = (s: DateShiftFlash['shifts'][number]) =>
    s.start_date && s.due_date ? `${s.start_date} to ${s.due_date}` : s.due_date ? `due ${s.due_date}` : `starting ${s.start_date}`;

/**
 * Spec 7.5 (2-R6): a due date moved with "ask every time", so nothing was shifted. Lists the issues that now start before
 * it is due and their proposed dates; "Shift dates" applies them, "Keep dates" leaves them. "Remember my choice" saves
 * always or never as the person's choice (the profile page changes it). Esc or a click outside only closes it: the date stays saved, nothing is shifted and nothing is remembered.
 */
export default function DateShiftDialog({ issue }: { issue: Issue }) {
    const { flash } = usePage().props as { flash?: { dateShift?: DateShiftFlash | null } };
    const [shown, setShown] = useState<DateShiftFlash | null>(null);
    const [open, setOpen] = useState(false);
    const [remember, setRemember] = useState(false);

    useEffect(() => {
        if (flash?.dateShift?.key !== issue.key) return;
        setShown(flash.dateShift);
        setRemember(false);
        setOpen(true);
    }, [flash?.dateShift, issue.key]);

    const answer = (shift: boolean) => {
        setOpen(false);
        // Keep dates without remembering changes nothing, so it sends nothing.
        if (shift || remember) {
            router.patch(route('issues.update', issue.key), { version: issue.version, shift_successors: shift, remember_date_shift: remember } as never, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <WarningConfirm
            open={open}
            title={`${issue.key} now ends after issues that follow it start`}
            warnings={(shown?.shifts ?? []).map((s) => (s.note ? { text: s.key, reason: s.note } : { text: `${s.key} would move to ${when(s)}` }))}
            confirmLabel="Shift dates"
            cancelLabel="Keep dates"
            onConfirm={() => answer(true)}
            onCancel={() => answer(false)}
            onClose={() => setOpen(false)}
        >
            <label className="flex items-center gap-2 text-md text-text">
                <Checkbox checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                Remember my choice
            </label>
        </WarningConfirm>
    );
}
