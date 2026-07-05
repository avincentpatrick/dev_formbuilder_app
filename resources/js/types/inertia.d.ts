// Shared Inertia props exposed by HandleInertiaRequests::share() — typed globally so usePage()
// is strongly typed everywhere (auth.user for the account menu, ui.theme for the theme toggle).

export type ThemeMode = 'system' | 'light' | 'dark';

export interface AppUser {
    id: string;
    name: string;
    email: string;
}

declare module '@inertiajs/core' {
    interface PageProps {
        auth: { user: AppUser | null };
        ui: { theme: { mode: ThemeMode; accent: string } };
        errors: Record<string, string>;
    }
}
