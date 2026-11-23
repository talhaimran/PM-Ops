import { StatusCategory } from '@/types';

const styles: Record<StatusCategory, [string, string]> = {
    to_do: ['tint-sky', 'bg-c-sky'],
    in_progress: ['tint-amber', 'bg-c-amber'],
    done: ['tint-good', 'bg-c-good'],
};

/** Tint classes for a status, for chips that aren't a full pill. */
export function lozengeClass(category: StatusCategory) {
    return styles[category][0];
}

/** The category's dot colour, e.g. a board column's status square. */
export function categoryDot(category: StatusCategory) {
    return styles[category][1];
}

/** Design system Pill: a dot plus the status's own name, coloured by its category. Colour is never the only signal. */
export default function StatusLozenge({ status, category, className = '' }: { status: string; category: StatusCategory; className?: string }) {
    const [tint, dot] = styles[category];

    return (
        <span className={`inline-flex h-pill-h max-w-full items-center gap-1.5 rounded-pill px-2.5 text-xs ${tint} ${className}`}>
            <span aria-hidden="true" className={`h-1.5 w-1.5 shrink-0 rounded-pill ${dot}`} />
            <span className="truncate">{status}</span>
        </span>
    );
}
