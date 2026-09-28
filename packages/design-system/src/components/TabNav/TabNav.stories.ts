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
 *
 * ⛔ EVERY LABEL AND KEY BELOW IS AN ILLUSTRATIVE FIXTURE. IT IS NOT THE HUB'S TAB SET, NOTHING COMPARES
 * THE TWO, AND THEY ALREADY DISAGREE (Increment M118, `R-a810cd42`). The authority is
 * `app/Support/Forms/FormTabSet::for()`, which DERIVES the strip per user from four capability checks — so
 * the real set varies by role and cannot be a constant anywhere. This file's `submissions` item is labelled
 * "Submissions" while `FormTabSet` emits "Responses", and `Overflowing` invents `share` and `versions`
 * keys the hub has never had. Both are left exactly as they are, deliberately: they are what makes this
 * warning checkable, and correcting them would restore the appearance of a faithful copy while removing
 * the only evidence that it is not one.
 *
 * ⚠️ A GATE WAS CONSIDERED AND DECLINED, WHICH IS NOT THE SAME AS OVERLOOKED. This package is
 * deliberately app-independent — it has no autoloader into `app/`, no PHP at build time, and gaining
 * either to keep a Storybook caption in step would be a far worse trade than a wrong caption. So the
 * fixture cannot import the derived set and no assertion can make the two disagree loudly. Read
 * `FormTabSet.php` for what the product shows; read these stories for what the component does.
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
