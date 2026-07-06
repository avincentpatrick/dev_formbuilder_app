import type { IconName } from '@meridian/design-system';
import type { AppAbilities } from '@/types/inertia';

export interface NavItem {
    key: string;
    label: string;
    icon: IconName;
    href?: string;
    enabled: boolean;
    // When set, the item is only shown if the current user holds this ability (auth.can) — the nav is
    // permission-aware (e.g. Members is an Owner/Admin management surface).
    gate?: keyof AppAbilities;
}

// Primary sidebar sections (DSR §3.4 order). Forms + Submissions are Phase-1 destinations that
// don't exist yet — shown as disabled "Soon" items so the eventual nav shape is visible now.
export const navItems: NavItem[] = [
    { key: 'forms', label: 'Forms', icon: 'forms', enabled: false },
    { key: 'submissions', label: 'Submissions', icon: 'submissions', enabled: false },
    { key: 'dashboard', label: 'Dashboard', icon: 'dashboard', href: '/dashboard', enabled: true },
    { key: 'members', label: 'Members', icon: 'users', href: '/members', enabled: true, gate: 'manageMembers' },
    { key: 'settings', label: 'Settings', icon: 'settings', href: '/settings', enabled: true },
];
