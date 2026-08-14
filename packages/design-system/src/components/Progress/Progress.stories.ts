import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Progress from './Progress.vue';

// Force dark so axe scans the dark pairs too — the Badge and Banner precedent. The fill is the interesting
// one here: `--mds-color-action-primary-fg` is re-pointed for dark rather than riding the primary ramp, so
// light passing says nothing about dark.
const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Progress',
    component: Progress,
    tags: ['autodocs'],
    argTypes: {
        tone: { control: 'select', options: ['default', 'warning'] },
        size: { control: 'select', options: ['sm', 'md'] },
        value: { control: { type: 'number' } },
        max: { control: { type: 'number' } },
        valueText: { control: 'text' },
    },
    args: { label: 'Capacity', value: 58, max: 100 },
} satisfies Meta<typeof Progress>;

export default meta;

type Story = StoryObj<typeof meta>;

export const Default: Story = {};

/** The reading the user thinks in, rather than a percentage of a cap that is not 100. */
export const DomainNativeValue: Story = {
    args: { label: 'Capacity', value: 58, max: 250, valueText: '58 / 250' },
};

export const Warning: Story = {
    args: { label: 'Capacity', value: 231, max: 250, valueText: '231 / 250', tone: 'warning' },
};

export const Medium: Story = {
    args: { label: 'Export', value: 40, size: 'md' },
};

/** Both ends of the range, because a 0% fill and a 100% fill are the two a rounding bug reaches first. */
export const Empty: Story = { args: { label: 'Export', value: 0 } };
export const Complete: Story = { args: { label: 'Export', value: 100 } };

/**
 * A degenerate cap is a call-site bug that must still render something honest — nothing can be complete
 * against a capacity of nothing, so it reads 0% rather than dividing by zero.
 */
export const ZeroMax: Story = { args: { label: 'Capacity', value: 3, max: 0, valueText: '3 / 0' } };

export const DefaultDark: Story = { decorators: [dark] };
export const WarningDark: Story = { ...Warning, decorators: [dark] };
export const MediumDark: Story = { ...Medium, decorators: [dark] };
