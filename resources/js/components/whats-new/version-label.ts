/**
 * The product version as a person reads it — M181 (`R-c7247dbf`, `D124`). One helper for all five places it shows (the
 * footer under every page, the sidebar foot, the sign-in card, the console bar, the landing page), so they cannot word
 * it five ways. A release is `v` and its three numbers; a development build says so rather than inventing a number; an
 * `APP_VERSION` override that is not three numbers (a hotfix label) shows as it was set.
 */
export function versionLabel(version: string | null | undefined): string {
    if (!version || version === 'dev') {
        return 'development build';
    }

    return /^\d+\.\d+\.\d+$/.test(version) ? `v${version}` : version;
}
