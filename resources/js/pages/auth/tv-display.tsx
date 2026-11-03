import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

function formatCode(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 8);

    return digits.length > 4
        ? `${digits.slice(0, 4)} ${digits.slice(4)}`
        : digits;
}

export default function TvDisplayPair() {
    const [code, setCode] = useState('');
    const ready = code.replace(/\D/g, '').length === 8;

    return (
        <>
            <Head title="TV Display" />

            <Form
                action="/tv-display/pair"
                method="post"
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="code">8-digit code</Label>
                            <Input
                                id="code"
                                name="code"
                                value={code}
                                onChange={(event) =>
                                    setCode(formatCode(event.target.value))
                                }
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                autoFocus
                                required
                                placeholder="0000 0000"
                                className="h-14 text-center text-2xl tracking-[0.3em] tabular-nums"
                            />
                            <InputError message={errors.code} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={!ready || processing}
                        >
                            {processing && <Spinner />}
                            Start display
                        </Button>
                    </div>
                )}
            </Form>

            <p className="text-center text-sm text-muted-foreground">
                <TextLink href="/login">Back to sign in</TextLink>
            </p>
        </>
    );
}

TvDisplayPair.layout = {
    title: 'Connect a TV Display',
    description: 'Enter the 8-digit code from your Super Administrator',
};