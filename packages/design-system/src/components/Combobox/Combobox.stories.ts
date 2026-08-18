import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Combobox from './Combobox.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

/**
 * MdsCombobox (DSR §3.4.1 / §4.5).
 *
 * ⚠️ THIS MARKUP HAS NEVER BEEN SCANNED BEFORE. It lived in `CommandPalette.vue`, in the application tree,
 * where Storybook's glob does not reach — and the palette recorded that gap against itself as a logged
 * deviation. Moving it here is what finally runs `aria-required-children` and `nested-interactive` over a
 * listbox this product has shipped since J1d.
 *
 * Every story renders WITH options. A closed combobox is an input and nothing else, so the scanner would
 * walk a subtree with no listbox in it and report success over the half that matters.
 */
const meta = {
    title: 'Components/Combobox',
    component: Combobox,
    tags: ['autodocs'],
    args: {
        modelValue: 'clinic',
        label: 'Search this workspace',
        listboxLabel: 'Search results',
        placeholder: 'Search forms, submissions, members and pages',
        status: '3 results',
        options: [
            { key: 'form:f1', label: 'Clinic Intake', group: 'Forms' },
            { key: 'form:f2', label: 'Clinic Referral', group: 'Forms' },
            { key: 'member:m1', label: 'Ada Lovelace', group: 'Members' },
            { key: 'see-all', label: 'See all results for “clinic”' },
        ],
    },
} satisfies Meta<typeof Combobox>;

export default meta;

type Story = StoryObj<typeof meta>;

/** 560px is the palette's dialog body, this component's only consumer and the width it has to survive. */
const framed = (inner: string) => (args: Record<string, unknown>) => ({
    components: { Combobox },
    setup: () => ({ args }),
    template: `<div style="max-width:560px;padding:1rem;">${inner}</div>`,
});

const PLAIN = `<Combobox v-bind="args" />`;

/**
 * The palette's real composition: heterogeneous rows carrying a title and a subtitle, through the scoped
 * slot. This is the shape `aria-required-children` would reject if the rows were buttons or list items.
 */
const RICH = `
    <Combobox v-bind="args">
        <template #option="{ option }">
            <span style="font-weight:500;">{{ option.label }}</span>
            <span style="font-size:0.8125rem;opacity:0.75;">{{ option.group ?? 'All results' }}</span>
        </template>
    </Combobox>
`;

export const Default: Story = { render: framed(PLAIN) };

export const WithRichOptions: Story = { render: framed(RICH) };

/**
 * Nothing matched. The listbox is ABSENT rather than empty, which is what keeps `aria-controls` and
 * `aria-expanded` honest — and the empty copy is one string for both "nothing matched" and "everything
 * that matched is invisible to you" (DSR §3.4.1's binding disclosure rule).
 */
export const NoResults: Story = {
    args: { options: [], status: '' },
    render: framed(`
        <Combobox v-bind="args">
            <template #empty><p style="margin:0;font-size:0.875rem;">No results.</p></template>
        </Combobox>
    `),
};

/** A single ungrouped run — no `role="group"` wrapper at all, and no invented heading. */
export const Ungrouped: Story = {
    args: {
        options: [
            { key: 'a', label: 'Dashboard' },
            { key: 'b', label: 'Members' },
            { key: 'c', label: 'Audit log' },
        ],
    },
    render: framed(PLAIN),
};

export const DefaultDark: Story = { render: framed(PLAIN), decorators: [dark] };
export const WithRichOptionsDark: Story = { render: framed(RICH), decorators: [dark] };
