import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router } from '@inertiajs/react';
import { Archive } from 'lucide-react';

/** 3-R17: every page of an archived workspace. Owners restore it; everyone else asks an Owner. */
export default function Archived({ name, restoreUrl }: { name: string; restoreUrl: string | null }) {
    return (
        <GuestLayout>
            <Head title="Archived" />
            <div className="flex flex-col items-center gap-3 text-center">
                <span className="flex h-10 w-10 items-center justify-center rounded-tile bg-card-2 text-muted">
                    <Archive className="h-5 w-5" aria-hidden="true" />
                </span>
                <h1 className="text-heading-2xl text-text">{name}</h1>
                <p className="text-muted">This workspace was archived after 12 months without activity</p>
                {!restoreUrl && <p className="text-muted">Ask an Owner to restore it</p>}
                <div className="mt-3 flex flex-wrap justify-center gap-3">
                    {restoreUrl && <PrimaryButton onClick={() => router.post(restoreUrl)}>Restore</PrimaryButton>}
                    <SecondaryButton onClick={() => router.visit(route('profile.edit'))}>Profile</SecondaryButton>
                    <SecondaryButton onClick={() => router.post(route('logout'))}>Log out</SecondaryButton>
                </div>
            </div>
        </GuestLayout>
    );
}
