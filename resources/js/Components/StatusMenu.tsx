import StatusLozenge, { lozengeClass } from '@/Components/StatusLozenge';
import { IssueOptions, StatusCategory } from '@/types';
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';
import { Check, ChevronDown } from 'lucide-react';

const groups: [StatusCategory, string][] = [
    ['to_do', 'To do'],
    ['in_progress', 'In progress'],
    ['done', 'Done'],
];

/** The issue's status picker: a listbox of the project's statuses grouped by category, each option its pill. */
export default function StatusMenu({
    value,
    category,
    color,
    statuses,
    disabled,
    onChange,
}: {
    value: string;
    category: StatusCategory;
    color?: string | null;
    statuses: IssueOptions['statuses'];
    disabled?: boolean;
    onChange: (status: string) => void;
}) {
    return (
        <Listbox value={value} onChange={onChange} disabled={disabled}>
            <div className="relative">
                <ListboxButton
                    aria-label="status"
                    className={`inline-flex h-control-h-sm items-center gap-1.5 rounded-pill px-3 text-xs ${lozengeClass(category, color)} ${disabled ? '' : 'hover:brightness-95'}`}
                >
                    <span className="truncate">{value}</span>
                    {!disabled && <ChevronDown className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />}
                </ListboxButton>
                <ListboxOptions className="absolute z-30 mt-1 max-h-72 min-w-48 overflow-auto rounded-ctl border border-line bg-card py-1 shadow-pop">
                    {groups.map(([group, label]) => {
                        const options = statuses.filter((s) => s.category === group);
                        return (
                            options.length > 0 && (
                                <div key={group} role="group" aria-label={label}>
                                    <p className="px-3 pb-1 pt-2 text-2xs font-semibold uppercase text-muted">{label}</p>
                                    {options.map((s) => (
                                        <ListboxOption key={s.name} value={s.name} className="flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5 data-[focus]:bg-card-2">
                                            <StatusLozenge status={s.name} category={s.category} color={s.color} />
                                            {s.name === value && <Check className="h-4 w-4 text-muted" aria-hidden="true" />}
                                        </ListboxOption>
                                    ))}
                                </div>
                            )
                        );
                    })}
                </ListboxOptions>
            </div>
        </Listbox>
    );
}
