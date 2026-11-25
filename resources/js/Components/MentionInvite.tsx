import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { router, usePage } from '@inertiajs/react';

/** 3-R9: a project admin mentioned someone who isn't in the project. The save went through; this offers the existing invitation. */
export default function MentionInvite({ project }: { project: string }) {
    const invite = (usePage().props.flash as { mentionInvite?: { project: string; emails: string[] } | null } | undefined)?.mentionInvite;
    const emails = invite?.project === project ? invite.emails : [];
    const clear = () => router.replace({ props: (props) => ({ ...props, flash: { ...(props.flash as object), mentionInvite: null } }), preserveScroll: true, preserveState: true });

    return (
        <Modal show={emails.length > 0} onClose={clear} maxWidth="md">
            <div className="space-y-4 p-6">
                <h2 className="text-heading-xl text-text">Invite {emails[0]} to this project?</h2>
                <p className="text-muted">
                    {emails.length > 1 ? `${emails.join(', ')} aren't` : `${emails[0]} isn't`} in {project}, so they weren't told about this mention and can't see the issue. Your text was saved.
                </p>
                <div className="flex justify-end gap-2">
                    <SecondaryButton onClick={clear}>Not now</SecondaryButton>
                    <PrimaryButton
                        onClick={() => {
                            // One at a time: the invitation form takes one email; the next shows after this one is sent.
                            router.post(route('projects.invitations.store', project), { email: emails[0], role: 'member' }, { preserveScroll: true, preserveState: true, onSuccess: clear });
                        }}
                    >
                        Invite {emails[0]}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}
