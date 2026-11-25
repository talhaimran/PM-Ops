import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';

/** The sidebar's inbox row (design system NotificationInbox): the bell, and the unread count when there is one. */
/** `row` and `rest` are the sidebar's row classes and its inactive colours, so this link switches between them like the others. */
export default function NotificationBell({ row, rest }: { row: string; rest: string }) {
    const unread = usePage().props.unread_notifications;
    const current = route().current('notifications.index');

    return (
        <Link
            href={route('notifications.index')}
            aria-current={current ? 'page' : undefined}
            aria-label={unread ? `Inbox, ${unread} unread` : 'Inbox'}
            className={`${row} ${current ? 'bg-side-pill text-on-side-pill' : rest}`}
        >
            <Bell className={`h-4 w-4 shrink-0 ${current ? 'text-side-accent' : ''}`} aria-hidden="true" />
            <span className="truncate">Inbox</span>
            {unread > 0 && <span className="ms-auto rounded-full bg-primary px-2 text-2xs text-on-primary">{unread > 99 ? '99+' : unread}</span>}
        </Link>
    );
}
