import InputError from '@/Components/InputError';
import { selectClass } from '@/Components/IssueFormModal';
import { StatusCategory } from '@/types';
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { router } from '@inertiajs/react';
import { useState } from 'react';

const categories: [StatusCategory, string][] = [
    ['to_do', 'To do'],
    ['in_progress', 'In progress'],
    ['done', 'Done'],
];
const CREATE = '\0create';

/** Adds a status to a column: pick one from the workspace library, or create one from the typed name and a category. */
export default function ColumnStatusAdder({ url, available }: { url: string; available: { name: string; category: StatusCategory }[] }) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<StatusCategory>('in_progress');
    const [error, setError] = useState('');
    const name = query.trim();
    const matches = available.filter((s) => s.name.toLowerCase().includes(name.toLowerCase()));
    const canCreate = name !== '' && !available.some((s) => s.name.toLowerCase() === name.toLowerCase());

    const add = (picked: string | null) => {
        if (!picked) return;
        router.post(url, (picked === CREATE ? { name, category } : { status: picked }) as never, {
            preserveScroll: true,
            onSuccess: () => {
                setQuery('');
                setError('');
            },
            onError: (e) => setError(e.name ?? e.status ?? e.category ?? ''),
        });
    };

    return (
        <Combobox value={null} onChange={add}>
            <div className="relative flex items-center gap-1">
                <ComboboxInput
                    aria-label="Add a status"
                    placeholder="Add a status…"
                    className="h-input-h w-40 rounded-ctl border-line-2 bg-card px-3 text-body text-text placeholder:text-faint focus:border-primary focus:ring-0"
                    displayValue={() => query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                {canCreate && (
                    <select aria-label="Category of the new status" className={`${selectClass} w-32`} value={category} onChange={(e) => setCategory(e.target.value as StatusCategory)}>
                        {categories.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                )}
                <ComboboxOptions className="absolute start-0 top-full z-30 mt-1 max-h-60 w-56 overflow-auto rounded-ctl border border-line bg-card py-1 shadow-pop empty:hidden">
                    {matches.map((s) => (
                        <ComboboxOption key={s.name} value={s.name} className="cursor-pointer px-3 py-1.5 text-sm data-[focus]:bg-card-2">
                            {s.name}
                        </ComboboxOption>
                    ))}
                    {canCreate && (
                        <ComboboxOption value={CREATE} className="cursor-pointer px-3 py-1.5 text-sm data-[focus]:bg-card-2">
                            Create “{name}”
                        </ComboboxOption>
                    )}
                </ComboboxOptions>
                <InputError className="absolute start-0 top-full mt-1 whitespace-nowrap" message={error} />
            </div>
        </Combobox>
    );
}
