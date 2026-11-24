import Avatar from '@/Components/Avatar';
import EmptyState from '@/Components/EmptyState';
import Pagination, { Paginated } from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import { issueUrl } from '@/issueUrl';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bell, Check } from 'lucide-react';
import { MouseEvent } from 'react';

interface Item {
    id: number;
    event: string;
    text: string;
    issue: string | null;
    agent: { name: string; owner: string } | null;
    actor: { name: string; email: string } | null;
    keys: string[];
    read: boolean;
    created_at: string;
}

export default function Index({ items }: { items: Paginated<Item> }) {
    const unread = usePage().props.unread_notifications;
    const markRead = (id: number, then?: () => void) =>
        router.post(route('notifications.read', id), {}, { preserveScroll: true, preserveState: true, onFinish: then });
    // A plain click marks the item read, then opens the issue; Cmd/Ctrl/Shift-click keep the browser's own behaviour.
    const open = (item: Item) => (e: MouseEvent) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        e.preventDefault();
        if (item.read) return router.visit(issueUrl(item.issue!));
        markRead(item.id, () => router.visit(issueUrl(item.issue!)));
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-display text-text">Inbox</h1>
                    <SecondaryButton disabled={unread === 0} onClick={() => router.post(route('notifications.read-all'), {}, { preserveScroll: true })}>
                        <Check className="h-4 w-4" aria-hidden="true" />
                        Mark all read
                    </SecondaryButton>
                </div>
            }
        >
            <Head title="Inbox" />

            <div className="space-y-4 px-4 pb-10 md:px-8">
                {items.data.length === 0 ? (
                    <EmptyState icon={Bell} title="You're all caught up." />
                ) : (
                    <ul className="divide-y divide-line overflow-hidden rounded-card bg-card shadow">
                        {items.data.map((item) => (
                            <li key={item.id} className="flex items-center gap-3 px-4 py-3">
                                <span className={`h-2 w-2 shrink-0 rounded-full ${item.read ? '' : 'bg-primary'}`} aria-label={item.read ? undefined : 'Unread'} />
                                {item.agent ? <Avatar agent name={item.agent.name} size="sm" /> : <Avatar name={item.actor?.name ?? 'PmOps'} email={item.actor?.email} size="sm" />}
                                <div className="min-w-0 flex-1">
                                    {item.issue ? (
                                        <Link href={issueUrl(item.issue)} onClick={open(item)} className={`block break-words hover:underline ${item.read ? 'text-muted' : 'text-text'}`}>
                                            {item.text}
                                        </Link>
                                    ) : (
                                        <p className={item.read ? 'text-muted' : 'text-text'}>{item.text}</p>
                                    )}
                                    {item.keys.length > 0 && <p className="break-words text-sm text-muted">{item.keys.join(', ')}</p>}
                                    <time dateTime={item.created_at} className="text-sm text-muted">
                                        {new Date(item.created_at).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })}
                                    </time>
                                </div>
                                {!item.read && (
                                    <button type="button" onClick={() => markRead(item.id)} className="flex h-control-h-sm shrink-0 items-center rounded-ctl px-2.5 text-sm text-text hover:bg-card-2">
                                        Mark read
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination page={items} />
            </div>
        </AuthenticatedLayout>
    );
}
