import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Menu from './Menu.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * ⚠️ EVERY STORY SETS `defaultOpen: true`, AND IT IS THE ONLY REASON THE ACCESSIBILITY JOB SEES ANYTHING.
 * A closed menu renders a trigger and nothing else, so the scanner would walk an empty subtree and report
 * success over a component it never examined.
 *
 * This is also the first time this markup has EVER been scanned. It lived in the application tree, where
 * Storybook's glob does not reach — which is how a `role="menu"` containing non-menuitem children survived
 * every increment since it was written. `AccountShape` is the composition that defect lived in.
 */
const meta = {
    title: 'Components/Menu',
    component: Menu,
    tags: ['autodocs'],
    argTypes: {
        align: { control: 'inline-radio', options: ['start', 'end'] },
    },
    args: {
        triggerLabel: 'Account menu',
        label: 'Account',
        align: 'end',
        defaultOpen: true,
        items: [
            { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
            { id: 'logout', label: 'Log out', icon: 'logout' },
        ],
    },
} satisfies Meta<typeof Menu>;

export default meta;

type Story = StoryObj<typeof meta>;

/** Room below the trigger so the panel is fully inside the scanned root rather than clipped by it. */
const framed = (template: string) => (args: Record<string, unknown>) => ({
    components: { Menu },
    setup: () => ({ args }),
    template: `<div style="display:flex;justify-content:flex-end;padding:1rem 1rem 16rem;">${template}</div>`,
});

const ACCOUNT_SHAPE = `
    <Menu v-bind="args">
        <template #trigger><span aria-hidden="true">AV</span></template>
        <template #header>
            <p style="margin:0;font-weight:600;">Ada Lovelace</p>
            <p style="margin:0;font-size:0.8125rem;">ada@example.test</p>
        </template>
    </Menu>
`;

const PLAIN = `
    <Menu v-bind="args">
        <template #trigger><span aria-hidden="true">⋯</span></template>
    </Menu>
`;

/**
 * The real composition, and the one the ARIA fix is about: identity content beside the items rather than
 * inside `role="menu"`. The header is the menu's description, so entering it announces who is signed in —
 * which the previous structure could not do at all.
 */
export const AccountShape: Story = { render: framed(ACCOUNT_SHAPE) };

export const NoHeader: Story = { render: framed(PLAIN) };

export const AlignStart: Story = {
    args: { align: 'start' },
    render: framed(PLAIN),
};

export const WithDangerItem: Story = {
    args: {
        items: [
            { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
            { id: 'delete', label: 'Delete workspace', icon: 'trash', tone: 'danger' },
        ],
    },
    render: framed(PLAIN),
};

/**
 * `aria-disabled`, never the native attribute — a natively disabled control leaves the arrow-key ring and
 * the accessibility tree, taking any explanation of why it is unavailable with it (DSR §3.4a).
 */
export const WithDisabledItem: Story = {
    args: {
        items: [
            { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
            { id: 'transfer', label: 'Transfer ownership', icon: 'shield', disabled: true },
        ],
    },
    render: framed(PLAIN),
};

export const AccountShapeDark: Story = { render: framed(ACCOUNT_SHAPE), decorators: [dark] };
export const WithDangerItemDark: Story = {
    args: {
        items: [
            { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
            { id: 'delete', label: 'Delete workspace', icon: 'trash', tone: 'danger' },
        ],
    },
    render: framed(PLAIN),
    decorators: [dark],
};
