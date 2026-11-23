// Edit conflict dialog helpers (spec 7.11, design system ConflictDialog). Pure functions, so they run under `node --test`.

/** What the server flashes when a save was stale (App\Exceptions\EditConflict): both versions of the fields sent. */
export type ConflictFlash = {
    key: string;
    version: number;
    url: string;
    yours: Record<string, unknown>;
    theirs: Record<string, unknown>;
    by: string | null;
    at: string | null;
};

/** A field value as text: lists joined, empty as "None". */
export function show(value: unknown): string {
    if (Array.isArray(value)) return value.length ? value.join(', ') : 'None';
    return value === null || value === undefined || value === '' ? 'None' : String(value);
}

/** "story_points" → "Story points". */
export function fieldLabel(field: string): string {
    const words = field.replace(/_/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * Where two texts differ, by words: the shared start and end, and each side's middle.
 * shortcut: one changed stretch, not a full diff; edits in two far-apart places show as one long middle. Use an LCS diff if that confuses people.
 */
export function diffWords(mine: string, theirs: string): { head: string; mine: string; theirs: string; tail: string } {
    const a = mine.split(/(\s+)/);
    const b = theirs.split(/(\s+)/);
    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) start++;
    let end = 0;
    while (end < a.length - start && end < b.length - start && a[a.length - 1 - end] === b[b.length - 1 - end]) end++;

    return {
        head: a.slice(0, start).join(''),
        mine: a.slice(start, a.length - end).join(''),
        theirs: b.slice(start, b.length - end).join(''),
        tail: a.slice(a.length - end).join(''),
    };
}

/** "30 seconds ago", "2 minutes ago", "3 days ago". */
export function ago(iso: string, now = Date.now()): string {
    const seconds = Math.max(0, Math.round((now - Date.parse(iso)) / 1000));
    const format = new Intl.RelativeTimeFormat('en', { numeric: 'always' });
    if (seconds < 60) return format.format(-seconds, 'second');
    if (seconds < 3600) return format.format(-Math.floor(seconds / 60), 'minute');
    if (seconds < 86400) return format.format(-Math.floor(seconds / 3600), 'hour');
    return format.format(-Math.floor(seconds / 86400), 'day');
}
