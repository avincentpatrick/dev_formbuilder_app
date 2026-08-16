import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Tabs from './Tabs.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * MdsTabs (DSR §3.4).
 *
 * ⚠️ THIS IS THE FIRST TIME THIS MARKUP HAS EVER BEEN SCANNED. It lived in `ConfigPanel.vue`, in the
 * application tree, where Storybook's glob does not reach — which is how a tablist with no aria-controls
 * and a panel sitting in the tab sequence survived every increment since it was written. The builder's
 * end-to-end specs click every tab on that page and have never asked what any of them points at.
 *
 * Every story renders real content in the panel. A story with an empty panel would let the scanner walk a
 * subtree with no relationships in it and report success over the half that matters.
 */
const meta = {
    title: 'Components/Tabs',
    component: Tabs,
    tags: ['autodocs'],
    args: {
        ariaLabel: 'Configuration sections',
        modelValue: 'basics',
        items: [
            { key: 'basics', label: 'Basics' },
            { key: 'validation', label: 'Validation' },
            { key: 'advanced', label: 'Advanced' },
        ],
    },
} satisfies Meta<typeof Tabs>;

export default meta;

type Story = StoryObj<typeof meta>;

/**
 * The panel holds a labelled control, because that is what a tab panel holds in this product and because a
 * panel of inert prose would not exercise the "no tabindex on the panel" decision at all — a redundant stop
 * in front of a real input is the thing that decision exists to prevent.
 */
const PANEL = `
    <Tabs v-bind="args">
        <label style="display:flex;flex-direction:column;gap:0.25rem;">
            <span>Label</span>
            <input type="text" value="Full name" style="padding:0.5rem;" />
        </label>
        <p style="margin:0;">Settings for the selected question.</p>
    </Tabs>
`;

const framed = (args: Record<string, unknown>) => ({
    components: { Tabs },
    setup: () => ({ args }),
    // 340px is the builder's right pane, this component's only consumer and the tightest box it has to
    // survive. The overflow story below is about exactly this width.
    template: `<div style="max-width:340px;padding:1rem;">${PANEL}</div>`,
});

export const Default: Story = { render: framed };

export const SecondSelected: Story = {
    args: { modelValue: 'validation' },
    render: framed,
};

/**
 * More tabs than the pane is wide. The strip scrolls rather than wrapping, and takes NO tabindex of its
 * own: `scrollable-region-focusable` fires only on a scroll region with no focusable descendants, and every
 * child here is a button. This is the story that would catch a well-meaning tabindex being added to it.
 */
export const Overflowing: Story = {
    args: {
        items: [
            { key: 'basics', label: 'Basics' },
            { key: 'options', label: 'Options' },
            { key: 'cascading', label: 'Levels' },
            { key: 'grid', label: 'Grid' },
            { key: 'geo', label: 'Map' },
            { key: 'media', label: 'Media' },
            { key: 'prefill', label: 'Prefill' },
            { key: 'validation', label: 'Validation' },
            { key: 'advanced', label: 'Advanced' },
        ],
    },
    render: framed,
};

/**
 * A model naming no item: nothing is selected, and the first tab still holds the single tab stop. Scanned
 * because it is a reachable state — the consumer's item list changes as its selection changes — and because
 * a strip that marked nothing AND was unreachable by keyboard would look identical here.
 */
export const NothingSelected: Story = {
    args: { modelValue: 'no-such-key' },
    render: framed,
};

export const DefaultDark: Story = { render: framed, decorators: [dark] };
export const OverflowingDark: Story = {
    args: {
        items: [
            { key: 'basics', label: 'Basics' },
            { key: 'options', label: 'Options' },
            { key: 'validation', label: 'Validation' },
            { key: 'advanced', label: 'Advanced' },
        ],
    },
    render: framed,
    decorators: [dark],
};
