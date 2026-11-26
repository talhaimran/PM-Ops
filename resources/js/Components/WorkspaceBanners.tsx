import { usePage } from '@inertiajs/react';
import { CreditCard, Eye, Lock } from 'lucide-react';
import { ReactNode } from 'react';

function Banner({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <p role="status" className="flex items-start gap-2 tint-warn px-4 py-2 text-sm md:px-8">
            {icon}
            <span>{children}</span>
        </p>
    );
}

const notices = {
    past_due: 'Payment failed. Update your payment method.',
    canceling: 'Your plan is set to cancel.',
    read_only: 'This workspace is over its plan limits.',
};

/** A plain link: the redirect leaves the app, which an Inertia visit can't follow. */
function BillingLink({ href }: { href: string | null | undefined }) {
    return href ? (
        <a href={href} className="underline">
            Billing
        </a>
    ) : null;
}

/** Notices across the top of every workspace page: a support session (3-R19), a suspended workspace (3-R16) and billing (3-R24). */
export default function WorkspaceBanners() {
    const { workspace, support_session: support, billing } = usePage().props;
    const icon = 'mt-0.5 h-4 w-4 shrink-0';

    return (
        <>
            {support && (
                <Banner icon={<Eye className={icon} aria-hidden="true" />}>
                    Support session: {support.staff} is viewing as {support.user}, ends at {support.ends_at}
                </Banner>
            )}
            {workspace?.state === 'suspended' && (
                <Banner icon={<Lock className={icon} aria-hidden="true" />}>This workspace is suspended. You can view and export, but not make changes.</Banner>
            )}
            {billing?.trial_days != null && (
                <Banner icon={<CreditCard className={icon} aria-hidden="true" />}>
                    Trial: {billing.trial_days} {billing.trial_days === 1 ? 'day' : 'days'} left <BillingLink href={billing.link} />
                </Banner>
            )}
            {billing?.notice && (
                <Banner icon={<CreditCard className={icon} aria-hidden="true" />}>
                    {notices[billing.notice]} <BillingLink href={billing.link} />
                </Banner>
            )}
        </>
    );
}
