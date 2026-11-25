import { Comment } from '@/types';
import { router } from '@inertiajs/react';
import { SmilePlus } from 'lucide-react';
import { useState } from 'react';

/** 3-R13: the fixed set, in the server's order (App\Enums\Reaction). */
export const REACTIONS = ['👍', '✅', '👀', '🎉', '❤️', '😄'];

/** Reactions on a comment: a pill per emoji in use (hover shows who), and an add button for people who can write. */
export default function Reactions({ comment, canReact }: { comment: Comment; canReact: boolean }) {
    const [picking, setPicking] = useState(false);
    const reactions = comment.reactions ?? [];
    const toggle = (emoji: string, mine: boolean) =>
        router.visit(route(mine ? 'comments.reactions.destroy' : 'comments.reactions.store', comment.id), { method: mine ? 'delete' : 'post', data: { emoji }, preserveScroll: true, preserveState: true });

    if (reactions.length === 0 && !canReact) return null;

    return (
        <div className="mt-1 flex flex-wrap items-center gap-1">
            {reactions.map((r) => (
                <button
                    key={r.emoji}
                    type="button"
                    disabled={!canReact}
                    aria-pressed={r.mine}
                    title={r.people.join(', ')}
                    aria-label={`${r.emoji} ${r.count}: ${r.people.join(', ')}`}
                    onClick={() => toggle(r.emoji, r.mine)}
                    className={`inline-flex h-control-h-sm items-center gap-1 rounded-pill border px-2 text-sm ${r.mine ? 'border-primary tint-cobalt' : 'border-line-2 text-muted hover:bg-card-2'}`}
                >
                    <span aria-hidden="true">{r.emoji}</span>
                    {r.count}
                </button>
            ))}
            {canReact && (
                <span className="inline-flex items-center gap-1">
                    <button
                        type="button"
                        aria-label="Add reaction"
                        aria-expanded={picking}
                        onClick={() => setPicking((p) => !p)}
                        className="inline-flex h-control-h-sm items-center justify-center px-2 rounded-pill text-muted hover:bg-card-2 hover:text-text"
                    >
                        <SmilePlus className="h-4 w-4" aria-hidden="true" />
                    </button>
                    {picking &&
                        REACTIONS.filter((e) => !reactions.some((r) => r.emoji === e && r.mine)).map((e) => (
                            <button
                                key={e}
                                type="button"
                                aria-label={`React ${e}`}
                                onClick={() => {
                                    setPicking(false);
                                    toggle(e, false);
                                }}
                                className="inline-flex h-control-h-sm items-center justify-center px-2 rounded-pill hover:bg-card-2"
                            >
                                {e}
                            </button>
                        ))}
                </span>
            )}
        </div>
    );
}
