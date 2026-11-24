// The board's live region (design system Accessibility page). Pure, so it runs under `node --test`.

/** What a drop says: a move only once the server took it; a blocked drop waiting for its confirm (2-D4) says so. */
export function dropAnnouncement(key: string, lane: string, index: number, total: number, state: 'moved' | 'confirm' | 'kept'): string {
    if (state === 'confirm') return `${key} not moved yet: confirm the move to ${lane}.`;
    if (state === 'kept') return `${key} not moved.`;
    return `${key} moved to ${lane}, position ${index + 1} of ${total}`;
}
