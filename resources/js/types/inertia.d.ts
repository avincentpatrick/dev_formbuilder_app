// Shared Inertia props exposed by HandleInertiaRequests::share() — typed globally so usePage()
// is strongly typed everywhere (auth.user for the account menu, ui.theme for the theme toggle).

export type ThemeMode = 'system' | 'light' | 'dark';

export interface AppUser {
    id: string;
    name: string;
    email: string;
}

// Ability gates the shell/pages consume (computed fail-closed in HandleInertiaRequests::share()).
export interface AppAbilities {
    manageMembers: boolean;
    transferOwnership: boolean;
    manageForms: boolean;
    viewSubmissions: boolean;
}

export type FlashToast = { type: 'success' | 'error' | 'info'; message: string };

declare module '@inertiajs/core' {
    interface PageProps {
        auth: { user: AppUser | null; can: AppAbilities };
        ui: { theme: { mode: ThemeMode; accent: string } };
        flash: { toast: FlashToast | null; xlsformWarnings?: string[] | null };
        errors: Record<string, string>;
    }
}
