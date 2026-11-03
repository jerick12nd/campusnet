import { Head, Link, useForm } from '@inertiajs/react';
import { Tv } from 'lucide-react';
import type { FormEvent } from 'react';

function formatCode(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 8);

    return digits.length > 4 ? `${digits.slice(0, 4)} ${digits.slice(4)}` : digits;
}

export default function TvDisplayPair() {
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const ready = data.code.replace(/\D/g, '').length === 8;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/tv-display/pair');
    };

    return (
        <>
            <Head title="TV Display" />

            <main className="flex min-h-screen items-center justify-center bg-[#060a13] p-6 text-white">
                <form onSubmit={submit} className="w-full max-w-xl space-y-8 rounded-3xl border border-white/10 bg-white/[0.06] p-10 text-center">
                    <div className="mx-auto flex size-16 items-center justify-center rounded-2xl bg-sky-500/90">
                        <Tv className="size-8" aria-hidden="true" />
                    </div>

                    <div>
                        <h1 className="text-3xl font-semibold">CampusNet TV Display</h1>
                        <p className="mt-2 text-lg text-white/70">Enter the 8-digit code from your Super Administrator.</p>
                    </div>

                    <div>
                        <label htmlFor="code" className="sr-only">
                            8-digit pairing code
                        </label>
                        <input
                            id="code"
                            value={data.code}
                            onChange={(event) => setData('code', formatCode(event.target.value))}
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            placeholder="0000 0000"
                            className="w-full rounded-2xl border border-white/20 bg-black/30 px-4 py-5 text-center text-5xl font-semibold tracking-[0.2em] tabular-nums outline-none focus:border-sky-400"
                        />
                        {errors.code && (
                            <p role="alert" className="mt-3 text-lg text-red-300">
                                {errors.code}
                            </p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={!ready || processing}
                        className="w-full rounded-2xl bg-sky-500 px-6 py-4 text-xl font-semibold text-white disabled:opacity-40"
                    >
                        {processing ? 'Connecting…' : 'Start display'}
                    </button>

                    <Link href="/login" className="block text-white/60 underline underline-offset-4">
                        Back to sign in
                    </Link>
                </form>
            </main>
        </>
    );
}