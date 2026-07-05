import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Card from './Card.vue';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const meta = {
    title: 'Components/Card',
    component: Card,
    tags: ['autodocs'],
    argTypes: {
        interactive: { control: 'boolean' },
        as: { control: 'select', options: ['div', 'button', 'a'] },
    },
    args: { interactive: false, as: 'div' },
    render: (args) => ({
        components: { Card },
        setup: () => ({ args }),
        template: `
            <Card v-bind="args" style="max-width:320px">
                <p style="margin:0 0 4px;font-weight:var(--mds-font-weight-semibold);color:var(--mds-color-text-heading)">Active forms</p>
                <p style="margin:0;color:var(--mds-color-text-secondary)">12 forms collecting responses right now.</p>
            </Card>
        `,
    }),
} satisfies Meta<typeof Card>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Static: Story = {};
export const Interactive: Story = { args: { interactive: true, as: 'button' } };
export const WithHeaderFooter: Story = {
    render: (args) => ({
        components: { Card },
        setup: () => ({ args }),
        template: `
            <Card v-bind="args" style="max-width:320px">
                <template #header><h3 style="margin:0;font:var(--mds-type-heading-4-font-weight) var(--mds-type-heading-4-font-size)/var(--mds-type-heading-4-line-height) var(--mds-font-family-display);color:var(--mds-color-text-heading)">This week</h3></template>
                <p style="margin:0;color:var(--mds-color-text-secondary)">248 submissions received.</p>
                <template #footer><span style="color:var(--mds-color-text-secondary);font-size:var(--mds-type-caption-font-size)">Updated just now</span></template>
            </Card>
        `,
    }),
};
export const StaticDark: Story = { decorators: [dark] };
export const InteractiveDark: Story = { args: { interactive: true, as: 'button' }, decorators: [dark] };
