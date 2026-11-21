import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import UiThemePicker from '@/components/ui-theme-picker';
import { useUiTheme } from '@/hooks/use-appearance';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { uiTheme } = useUiTheme();

    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-10">
                <section className="space-y-6">
                    <Heading
                        variant="small"
                        title="Theme"
                        description="Choose how CampusNet looks on this browser"
                    />
                    <UiThemePicker />
                </section>

                <section className="space-y-6">
                    <Heading
                        variant="small"
                        title="Color mode"
                        description="Switch between light and dark"
                    />

                    {uiTheme === 'glass' ? (
                        <AppearanceTabs />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            This theme is light only. Choose Liquid Glass to use dark mode.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};