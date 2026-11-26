import { usePage } from '@inertiajs/react';
import { Eye, Lock } from 'lucide-react';
import { ReactNode } from 'react';

function Banner({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <p role="status" className="flex items-start gap-2 tint-warn px-4 py-2 text-sm md:px-8">
            {icon}
            <span>{children}</span>
        </p>
    );
}

/** Notices across the top of every workspace page: a support session (3-R19) and a suspended workspace (3-R16). */
export default function WorkspaceBanners() {
    const { workspace, support_session: support } = usePage().props;
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
        </>
    );
}
