import type { Meta, StoryObj } from '@storybook/vue3';
import Badge from '../components/Badge/Badge.vue';
import Button from '../components/Button/Button.vue';
import Checkbox from '../components/Checkbox/Checkbox.vue';
import FormField from '../components/FormField/FormField.vue';
import SegmentedControl from '../components/SegmentedControl/SegmentedControl.vue';
import TextInput from '../components/TextInput/TextInput.vue';

/**
 * Increment G11 — the §2.9 personalization axes, as component-level axe coverage.
 *
 * These stories exist because the axes reach components through re-pointed semantic tokens, so the
 * only way to know a personalized combination is accessible is to render real components under it.
 * Two distinct concerns:
 *
 *  · TealLight / TealDark cover the accent's new colour pairings. The dark one matters most: before
 *    G11 the accent stub had no dark variant at all, and because [data-accent] carried the same
 *    specificity as [data-theme-mode='dark'] and came later in source order, teal won in dark mode
 *    and painted the light #1B5E5E on the #123350 ground — 1.74:1 for action-primary-fg and the focus
 *    ring, against minimums of 4.5 and 3. Nothing caught it because nothing ever set the attribute.
 *
 *  · ExtraLarge / DyslexiaFont cover metrics rather than colour: whether components still lay out
 *    correctly when the type scale grows 25% or the body face is swapped for a substantially wider one.
 *
 * The attributes are applied by the shared decorator in .storybook/preview.ts via the
 * `personalization` parameter — it writes to document.documentElement because these are :root[…]
 * selectors, and it clears every axis on every story so state cannot leak between them.
 */

const panel = () => ({
    components: { Badge, Button, Checkbox, FormField, SegmentedControl, TextInput },
    setup: () => ({
        themeOptions: [
            { value: 'light', label: 'Light' },
            { value: 'dark', label: 'Dark' },
            { value: 'system', label: 'Match System' },
        ],
    }),
    // Every surface the accent touches (primary fill, focus ring, checked control, selected chip),
    // plus semantic badges — which must stay unchanged, since accent and semantic colour are
    // architecturally separate layers (§2.2).
    template: `
        <main style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;
                     gap:var(--mds-space-6)">
            <h1 style="margin:0;font-family:var(--mds-font-family-display);
                       font-size:var(--mds-type-heading-1-font-size);
                       line-height:var(--mds-type-heading-1-line-height);
                       color:var(--mds-color-text-heading)">Appearance</h1>

            <p style="margin:0;font-size:var(--mds-type-body-md-font-size);
                      line-height:var(--mds-type-body-md-line-height);
                      color:var(--mds-color-text-body)">
                Body copy in the Body type role — this is what the dyslexia-friendly face replaces, and
                what the text-size scale grows. Headings and code are deliberately untouched by the face
                swap.
            </p>

            <div style="display:flex;gap:var(--mds-space-3);flex-wrap:wrap">
                <Button variant="primary">Save changes</Button>
                <Button variant="secondary">Cancel</Button>
                <Button variant="destructive">Delete</Button>
            </div>

            <SegmentedControl :model-value="'light'" :options="themeOptions" ariaLabel="Theme" />

            <FormField label="Workspace name" input-id="ws">
                <TextInput id="ws" :model-value="'Meridian'" />
            </FormField>

            <Checkbox :model-value="true" label="Use a dyslexia-friendly font" />

            <div style="display:flex;gap:var(--mds-space-2);flex-wrap:wrap">
                <Badge variant="success">Active</Badge>
                <Badge variant="warning">Invited</Badge>
                <Badge variant="danger">Suspended</Badge>
                <Badge variant="info">Draft</Badge>
            </div>

            <code style="font-family:var(--mds-font-family-mono);
                         font-size:var(--mds-type-code-font-size);
                         line-height:var(--mds-type-code-line-height);
                         color:var(--mds-color-text-body)">score &gt;= 12 and consent = 'yes'</code>
        </main>
    `,
});

const meta: Meta = {
    title: 'Foundations/Personalization',
    render: panel,
};

export default meta;
type Story = StoryObj<typeof meta>;

export const TealLight: Story = {
    parameters: { personalization: { accent: 'teal' } },
    globals: { themeMode: 'light' },
};

export const TealDark: Story = {
    parameters: { personalization: { accent: 'teal' } },
    globals: { themeMode: 'dark' },
};

export const ExtraLarge: Story = {
    parameters: { personalization: { fontSize: 'extra_large' } },
};

export const DyslexiaFont: Story = {
    parameters: { personalization: { dyslexia: true } },
};

/** The maximum-stress combination — the same one the Playwright reflow loop scans. */
export const MaxPersonalization: Story = {
    parameters: {
        personalization: { accent: 'teal', fontSize: 'extra_large', dyslexia: true },
    },
    globals: { themeMode: 'dark' },
};
