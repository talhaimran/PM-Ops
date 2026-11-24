import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';

/** The sidebar's inbox row (design system NotificationInbox): the bell, and the unread count when there is one. */
export default function NotificationBell({ className }: { className: string }) {
    const unread = usePage().props.unread_notifications;
    const current = route().current('notifications.index');

    return (
        <Link
            href={route('notifications.index')}
            aria-current={current ? 'page' : undefined}
            aria-label={unread ? `Inbox, ${unread} unread` : 'Inbox'}
            className={`${className} ${current ? 'bg-side-pill text-on-side-pill' : ''}`}
        >
            <Bell className={`h-4 w-4 shrink-0 ${current ? 'text-side-accent' : ''}`} aria-hidden="true" />
            <span className="truncate">Inbox</span>
            {unread > 0 && <span className="ms-auto rounded-full bg-primary px-2 text-2xs text-on-primary">{unread > 99 ? '99+' : unread}</span>}
        </Link>
    );
}
