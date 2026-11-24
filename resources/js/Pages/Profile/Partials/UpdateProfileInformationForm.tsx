import { selectClass } from '@/Components/IssueFormModal';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}: {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            date_shift: user.date_shift ?? 'ask',
            auto_watch: user.auto_watch ?? true,
        });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-heading-2xl text-text">
                    Profile information
                </h2>

                <p className="mt-1 text-sm text-muted">
                    Update your account's profile information and email address.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Name" />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.email} />
                </div>

                <div>
                    <InputLabel htmlFor="date_shift" value="When a due date moves later" />

                    <select
                        id="date_shift"
                        className={`${selectClass} mt-1 block w-full`}
                        value={data.date_shift}
                        onChange={(e) => setData('date_shift', e.target.value as typeof data.date_shift)}
                    >
                        <option value="ask">Ask me whether to shift the issues that follow</option>
                        <option value="always">Always shift the issues that follow</option>
                        <option value="never">Never shift them</option>
                    </select>

                    <InputError className="mt-2" message={errors.date_shift} />
                </div>

                <div>
                    <label className="flex items-center gap-2 text-md text-text">
                        <Checkbox checked={data.auto_watch} onChange={(e) => setData('auto_watch', e.target.checked)} />
                        Watch issues I create, am assigned or comment on
                    </label>

                    <InputError className="mt-2" message={errors.auto_watch} />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-text">
                            Your email address is unverified.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-ctl text-sm text-muted underline hover:text-text"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-t-good">
                                A new verification link has been sent to your
                                email address.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-muted">
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
