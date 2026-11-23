import { StatusCategory } from '@/types';

const styles: Record<StatusCategory, [string, string]> = {
    to_do: ['tint-sky', 'bg-c-sky'],
    in_progress: ['tint-amber', 'bg-c-amber'],
    done: ['tint-good', 'bg-c-good'],
};

// Spec 7.4: the colours an admin may give a status. Literal classes, so Tailwind sees them.
const palette: Record<string, [string, string]> = {
    slate: ['tint-slate', 'bg-c-slate'],
    sky: ['tint-sky', 'bg-c-sky'],
    amber: ['tint-amber', 'bg-c-amber'],
    cobalt: ['tint-cobalt', 'bg-c-cobalt'],
    coral: ['tint-coral', 'bg-c-coral'],
    teal: ['tint-teal', 'bg-c-teal'],
    sand: ['tint-sand', 'bg-c-sand'],
    good: ['tint-good', 'bg-c-good'],
};

/** Dot class of a palette colour, for swatches. */
export function swatchClass(color: string) {
    return palette[color]?.[1] ?? '';
}

/** Tint classes for a status, for chips that aren't a full pill. */
export function lozengeClass(category: StatusCategory, color?: string | null) {
    return (color && palette[color]?.[0]) || styles[category][0];
}

/** The category's dot colour, e.g. a board column's status square. */
export function categoryDot(category: StatusCategory) {
    return styles[category][1];
}

/** Design system Pill: a dot plus the status's own name, coloured by its category (or its custom colour). Colour is never the only signal. */
export default function StatusLozenge({ status, category, color, className = '' }: { status: string; category: StatusCategory; color?: string | null; className?: string }) {
    const [tint, dot] = (color && palette[color]) || styles[category];

    return (
        <span className={`inline-flex h-pill-h max-w-full items-center gap-1.5 rounded-pill px-2.5 text-xs ${tint} ${className}`}>
            <span aria-hidden="true" className={`h-1.5 w-1.5 shrink-0 rounded-pill ${dot}`} />
            <span className="truncate">{status}</span>
        </span>
    );
}
