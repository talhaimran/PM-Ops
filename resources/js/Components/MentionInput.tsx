import Avatar from '@/Components/Avatar';
import { TextareaHTMLAttributes, useId, useRef, useState } from 'react';

export interface Mentionable {
    name: string;
    email: string;
}

/** The `@name` being typed just before the caret: where it starts and what follows the "@". */
function tokenAt(text: string, caret: number): { start: number; query: string } | null {
    const m = /(?:^|\s)@([^\s@]*)$/.exec(text.slice(0, caret));
    return m ? { start: caret - m[1].length - 1, query: m[1].toLowerCase() } : null;
}

/** Spec 7.10 (3-D3): a textarea whose `@` opens a list of the project's people; picking one writes `@email`, which is how a mention is stored. */
export default function MentionInput({
    value,
    onChange,
    members,
    className = '',
    ...rest
}: { value: string; onChange: (value: string) => void; members: Mentionable[] } & Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'value' | 'onChange'>) {
    const ref = useRef<HTMLTextAreaElement>(null);
    const listId = useId();
    const [token, setToken] = useState<{ start: number; query: string } | null>(null);
    const [active, setActive] = useState(0);
    const matches = token ? members.filter((m) => m.name.toLowerCase().includes(token.query) || m.email.toLowerCase().includes(token.query)).slice(0, 6) : [];
    const open = matches.length > 0;

    const pick = (m: Mentionable) => {
        const el = ref.current;
        if (!el || !token) return;
        const caret = el.selectionStart;
        const inserted = `@${m.email} `;
        onChange(value.slice(0, token.start) + inserted + value.slice(caret));
        setToken(null);
        const at = token.start + inserted.length;
        requestAnimationFrame(() => {
            el.focus();
            el.setSelectionRange(at, at);
        });
    };

    return (
        <div className="relative">
            <textarea
                {...rest}
                ref={ref}
                value={value}
                className={className}
                role="combobox"
                aria-expanded={open}
                aria-controls={listId}
                aria-autocomplete="list"
                onChange={(e) => {
                    onChange(e.target.value);
                    setToken(tokenAt(e.target.value, e.target.selectionStart));
                    setActive(0);
                }}
                onBlur={() => setToken(null)}
                onKeyDown={(e) => {
                    if (open) {
                        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                            e.preventDefault();
                            setActive((i) => (i + (e.key === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length);
                            return;
                        }
                        if (e.key === 'Enter' || e.key === 'Tab') {
                            e.preventDefault();
                            pick(matches[active]);
                            return;
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            setToken(null);
                            return;
                        }
                    }
                    rest.onKeyDown?.(e);
                }}
            />
            {open && (
                <ul id={listId} role="listbox" aria-label="People to mention" className="absolute z-50 mt-1 max-h-56 w-full max-w-sm overflow-auto rounded-ctl border border-line bg-card py-1 shadow-pop">
                    {matches.map((m, i) => (
                        <li
                            key={m.email}
                            role="option"
                            aria-selected={i === active}
                            // mousedown, before the textarea's blur closes the list.
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(m);
                            }}
                            className={`flex cursor-pointer items-center gap-2 px-3 py-1.5 ${i === active ? 'bg-card-2' : ''}`}
                        >
                            <Avatar name={m.name} email={m.email} />
                            <span className="min-w-0 flex-1 truncate text-text">{m.name}</span>
                            <span className="truncate text-xs text-muted">{m.email}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
