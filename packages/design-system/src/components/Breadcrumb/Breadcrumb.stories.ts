import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Breadcrumb from './Breadcrumb.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Breadcrumb',
    component: Breadcrumb,
    tags: ['autodocs'],
    args: {
        items: [
            { label: 'Forms', href: '/forms' },
            { label: 'Clinic Intake', href: '/forms/abc' },
            { label: 'Responses' },
        ],
    },
} satisfies Meta<typeof Breadcrumb>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Default: Story = {};

/** The two-crumb case, which is what most nested pages in this app actually carry. */
export const TwoLevels: Story = {
    args: { items: [{ label: 'Submissions', href: '/submissions' }, { label: 'Response 7K4M2QXB' }] },
};

/**
 * The deepest trail §3.4 names. Also the case that shows the tail wrapping rather than scrolling at a
 * narrow viewport — a trail is read once, so a second line beats a scroll container the reader must find.
 */
export const DeepAndLong: Story = {
    args: {
        items: [
            { label: 'Forms', href: '/forms' },
            { label: 'Household Food Security Baseline Survey 2026', href: '/forms/abc' },
            { label: 'Responses', href: '/forms/abc/submissions' },
            { label: 'Response 7K4M2QXB' },
        ],
    },
};

/** A single crumb is still the current page — it renders as text, never as a link to itself. */
export const SingleCrumb: Story = { args: { items: [{ label: 'Forms', href: '/forms' }] } };

export const Dark: Story = { decorators: [dark] };
