import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Icon from './Icon.vue';
import { icons } from './icons';

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const names = Object.keys(icons);

const meta = {
    title: 'Components/Icon',
    component: Icon,
    tags: ['autodocs'],
    argTypes: {
        name: { control: 'select', options: names },
        size: { control: 'select', options: ['sm', 'md', 'lg'] },
        label: { control: 'text' },
    },
    args: { name: 'dashboard', size: 'md' },
} satisfies Meta<typeof Icon>;

export default meta;
type Story = StoryObj<typeof meta>;

// The full registry as a labelled grid (icons decorative; the name captions carry the text).
const gridRender = () => ({
    components: { Icon },
    setup: () => ({ names }),
    template: `
        <ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:var(--mds-space-4);list-style:none;margin:0;padding:0;color:var(--mds-color-text-secondary)">
            <li v-for="n in names" :key="n" style="display:flex;flex-direction:column;align-items:center;gap:var(--mds-space-2);color:var(--mds-color-text-body)">
                <Icon :name="n" size="lg" />
                <span style="font-size:var(--mds-type-caption-font-size)">{{ n }}</span>
            </li>
        </ul>
    `,
});

export const Gallery: Story = { render: gridRender };
export const GalleryDark: Story = { render: gridRender, decorators: [dark] };

export const Sizes: Story = {
    render: () => ({
        components: { Icon },
        template: `
            <div style="display:flex;align-items:center;gap:var(--mds-space-4);color:var(--mds-color-text-body)">
                <Icon name="settings" size="sm" />
                <Icon name="settings" size="md" />
                <Icon name="settings" size="lg" />
            </div>
        `,
    }),
};

// A standalone, meaningful icon exposes an accessible name via `label`.
export const Labelled: Story = { args: { name: 'bell', label: 'Notifications', size: 'lg' } };
