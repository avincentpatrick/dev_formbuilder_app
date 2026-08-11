import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import TabNav from './TabNav.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * The form hub's strip is the first consumer (Increment J2b). Every story renders the real shape — a
 * resource-named landmark whose items are links — because the a11y scan these stories drive is this
 * component's only automated axe gate outside the two e2e specs that happen to load a page using it.
 */
const meta = {
    title: 'Components/TabNav',
    component: TabNav,
    tags: ['autodocs'],
    args: {
        ariaLabel: 'Clinic Intake',
        current: 'overview',
        items: [
            { key: 'overview', label: 'Overview', href: '#overview', icon: 'forms' },
            { key: 'submissions', label: 'Submissions', href: '#submissions', icon: 'submissions', badge: 128 },
            { key: 'builder', label: 'Builder', href: '#builder', icon: 'edit' },
            { key: 'analytics', label: 'Analytics', href: '#analytics', icon: 'chart-bar' },
        ],
    },
} satisfies Meta<typeof TabNav>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

/** A later item active — proves the underline follows `current` rather than sitting on the first item. */
export const SecondaryTabActive: Story = { args: { current: 'analytics' } };

/**
 * The set a role with no `forms.edit` capacity sees. Refused destinations are ABSENT, never rendered
 * disabled — ADR-0011 §D9's absent-not-locked doctrine, and the same rule J1's search arms follow.
 */
export const ReducedForRole: Story = {
    args: {
        items: [
            { key: 'overview', label: 'Overview', href: '#overview', icon: 'forms' },
            { key: 'submissions', label: 'Submissions', href: '#submissions', icon: 'submissions', badge: 128 },
        ],
    },
};

/** Labels long enough to overflow, which is the state the horizontal scroll exists for. */
export const Overflowing: Story = {
    args: {
        items: [
            { key: 'overview', label: 'Overview', href: '#a' },
            { key: 'submissions', label: 'Collected responses', href: '#b', badge: '1,284' },
            { key: 'builder', label: 'Question builder', href: '#c' },
            { key: 'analytics', label: 'Analytics and reports', href: '#d' },
            { key: 'share', label: 'Share and distribution', href: '#e' },
            { key: 'versions', label: 'Version history', href: '#f' },
        ],
    },
};

export const Dark: Story = { decorators: [dark] };
