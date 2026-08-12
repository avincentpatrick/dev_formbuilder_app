import type { Meta, StoryObj, Decorator } from '@storybook/vue3';
import Badge from '../components/Badge/Badge.vue';
import Button from '../components/Button/Button.vue';
import Card from '../components/Card/Card.vue';
import IconButton from '../components/IconButton/IconButton.vue';
import SegmentedControl from '../components/SegmentedControl/SegmentedControl.vue';
import { statusVariant } from '../components/Badge/status-variant';

// The composed forms-list CARD GRID (JR3) — a labelled list of cards, each led by a per-form identity
// hue, with the cards/table view toggle above it.
//
// ⚠️ THIS STORY IS THE ONLY AUTOMATED COVERAGE THE GRID WILL EVER HAVE, and that is measured rather
// than assumed. The real grid lives in the app tree (`resources/js/Pages/forms/Index.vue` +
// `components/forms/FormCard.vue`), and `.storybook/main.ts` globs
// `../src/**/*.stories.@(ts|tsx)` — package sources only — so an app-tree component is scanned by
// nothing. Neither is the visual result: no test in this repo asserts a radius, a shadow, a padding or
// a row height, and there is no visual-regression harness at all. What this story CAN catch, and what
// it exists for, is the composed-level accessibility the isolated component stories cannot see:
//
//   - heading order across the whole page (h1 → the filter bar's h2 → each card's h2);
//   - `list`/`listitem` semantics surviving `list-style: none` — hence the explicit `role="list"`;
//   - real text-on-surface contrast for all SIX identity hues, in BOTH themes, which is the half of
//     the identity scale that a token unit test measures in the abstract and this measures as painted.
//
// The card markup below is a faithful copy of `FormCard.vue`'s, not an approximation: if the two
// diverge, this story stops testing the thing it is named after. The tint/hue pairing in particular
// must stay — a solid identity fill with white initials is the version that fails contrast.

const dark: Decorator = (story) => {
    document.documentElement.setAttribute('data-theme-mode', 'dark');
    return story();
};

const forms = [
    {
        id: '1', identity: 1, initials: 'PI', title: 'Patient Intake', status: 'published',
        description: 'Registration and history for new patients, including consent.',
        responses: 842, drafts: 6, last: '2 hours ago', capacity: { used: 312, cap: 500 }, schedule: null,
    },
    {
        id: '2', identity: 2, initials: 'CH', title: 'Community Health Survey 2026', status: 'published',
        description: 'Annual household survey across five districts.',
        responses: 301, drafts: 2, last: 'yesterday', capacity: null, schedule: 'Accepting responses',
    },
    {
        id: '3', identity: 3, initials: 'FS', title: 'Field Site Assessment', status: 'published',
        description: 'Site conditions, staffing and supply checklist.',
        responses: 61, drafts: 0, last: '3 days ago', capacity: { used: 61, cap: 75 }, schedule: null,
    },
    {
        id: '4', identity: 4, initials: 'RL', title: 'Referral Log', status: 'published',
        description: 'Onward referrals to partner clinics.',
        responses: 0, drafts: 0, last: '—', capacity: null, schedule: 'Opens in 3 days',
    },
    {
        id: '5', identity: 5, initials: 'SF', title: 'Staff Feedback 2025', status: 'archived',
        description: 'Anonymous quarterly staff pulse.',
        responses: 128, drafts: 1, last: 'last week', capacity: null, schedule: 'Closed',
    },
    {
        id: '6', identity: 6, initials: 'QR', title: 'Quarterly Report', status: 'draft',
        description: 'Never published — still a draft.',
        responses: 0, drafts: 0, last: '—', capacity: null, schedule: 'Not published',
    },
];

const viewOptions = [
    { value: 'grid', label: 'Cards', icon: 'layout' as const },
    { value: 'table', label: 'Table', icon: 'list' as const },
];

const facets = [
    { value: null, label: 'All', count: 6 },
    { value: 'live', label: 'Live', count: 4 },
    { value: 'draft', label: 'Draft', count: 1 },
    { value: 'closing_soon', label: 'Closing soon', count: 1 },
];

const render = () => ({
    components: { Badge, Button, Card, IconButton, SegmentedControl },
    setup: () => ({ forms, viewOptions, facets, statusVariant }),
    template: `
        <main style="max-width:1200px;margin:0 auto;padding:var(--mds-space-8)">
            <header style="display:flex;align-items:center;justify-content:space-between;
                           gap:var(--mds-space-4);margin-bottom:var(--mds-space-6)">
                <h1 style="margin:0;font-size:var(--mds-type-heading-1-font-size);
                           line-height:var(--mds-type-heading-1-line-height);
                           color:var(--mds-color-text-heading)">Forms</h1>
                <div style="display:flex;gap:var(--mds-space-3);align-items:center">
                    <SegmentedControl model-value="grid" :options="viewOptions" ariaLabel="Layout" compact />
                    <Button variant="primary" icon-left="plus">New form</Button>
                </div>
            </header>

            <section aria-labelledby="filters-heading" style="margin-bottom:var(--mds-space-5)">
                <h2 id="filters-heading" style="margin:0 0 var(--mds-space-3);
                    font-size:var(--mds-type-heading-4-font-size);
                    line-height:var(--mds-type-heading-4-line-height);
                    color:var(--mds-color-text-heading)">Filters</h2>
                <div role="group" aria-label="Filter by state"
                     style="display:flex;flex-wrap:wrap;gap:var(--mds-space-2)">
                    <button v-for="f in facets" :key="f.value ?? 'all'" type="button"
                        :aria-pressed="f.value === null"
                        :style="[
                            'display:inline-flex;align-items:center;gap:var(--mds-space-2);min-height:32px;' +
                            'padding:var(--mds-space-1) var(--mds-space-3);border-radius:var(--mds-radius-full);' +
                            'font-family:var(--mds-font-family-body);font-size:var(--mds-type-label-font-size);' +
                            'font-weight:var(--mds-font-weight-medium);cursor:pointer;',
                            f.value === null
                                ? 'background:var(--mds-color-action-primary-bg);color:var(--mds-color-text-on-primary);' +
                                  'border:1px solid var(--mds-color-action-primary-bg);'
                                : 'background:var(--mds-color-bg-surface);color:var(--mds-color-text-secondary);' +
                                  'border:1px solid var(--mds-color-border-strong);'
                        ]">
                        {{ f.label }}
                        <b style="font-variant-numeric:tabular-nums">{{ f.count }}</b>
                    </button>
                </div>
            </section>

            <ul role="list" aria-label="Forms"
                style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));
                       gap:var(--mds-space-4);margin:0;padding:0;list-style:none">
                <li v-for="form in forms" :key="form.id" style="display:flex">
                    <Card :style="{
                        '--form-card-identity': 'var(--mds-form-identity-' + form.identity + ')',
                        position: 'relative', overflow: 'hidden', display: 'flex',
                        flexDirection: 'column', width: '100%', height: '100%',
                        containerType: 'inline-size',
                    }">
                        <template #header>
                            <div style="display:flex;align-items:flex-start;gap:var(--mds-space-3)">
                                <span aria-hidden="true" style="display:grid;place-items:center;flex:none;
                                    width:38px;height:38px;border-radius:var(--mds-radius-lg);
                                    background:color-mix(in srgb, var(--form-card-identity) 12%, transparent);
                                    color:var(--form-card-identity);
                                    font-size:var(--mds-type-caption-font-size);
                                    font-weight:var(--mds-font-weight-bold);
                                    letter-spacing:var(--mds-tracking-wide)">{{ form.initials }}</span>
                                <h2 style="flex:1;min-width:0;margin:0;
                                    font-size:var(--mds-type-heading-4-font-size);
                                    line-height:var(--mds-type-heading-4-line-height);
                                    font-weight:var(--mds-font-weight-semibold)">
                                    <a :href="'#form-' + form.id"
                                       style="color:var(--mds-color-action-primary-fg);
                                              font-weight:var(--mds-font-weight-medium);
                                              text-decoration:none">{{ form.title }}</a>
                                </h2>
                                <Badge v-bind="statusVariant(form.status)" dot style="flex:none" />
                            </div>
                            <p style="margin:var(--mds-space-2) 0 0;
                                      font-size:var(--mds-type-body-sm-font-size);
                                      line-height:var(--mds-type-body-sm-line-height);
                                      color:var(--mds-color-text-secondary)">{{ form.description }}</p>
                        </template>

                        <dl style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));
                                   gap:var(--mds-space-3);margin:0;padding:var(--mds-space-3) 0;
                                   border-top:1px solid var(--mds-color-border-default);
                                   border-bottom:1px solid var(--mds-color-border-default)">
                            <div style="display:flex;flex-direction:column-reverse;gap:2px;min-width:0">
                                <dt style="font-size:var(--mds-type-caption-font-size);
                                    font-weight:var(--mds-font-weight-semibold);
                                    letter-spacing:var(--mds-tracking-wide);text-transform:uppercase;
                                    color:var(--mds-color-text-secondary)">Responses</dt>
                                <dd style="margin:0;font-size:var(--mds-type-heading-3-font-size);
                                    line-height:var(--mds-type-heading-3-line-height);
                                    font-weight:var(--mds-font-weight-bold);font-variant-numeric:tabular-nums;
                                    color:var(--mds-color-action-primary-fg)">{{ form.responses }}</dd>
                            </div>
                            <div style="display:flex;flex-direction:column-reverse;gap:2px;min-width:0">
                                <dt style="font-size:var(--mds-type-caption-font-size);
                                    font-weight:var(--mds-font-weight-semibold);
                                    letter-spacing:var(--mds-tracking-wide);text-transform:uppercase;
                                    color:var(--mds-color-text-secondary)">In progress</dt>
                                <dd style="margin:0;font-size:var(--mds-type-heading-3-font-size);
                                    line-height:var(--mds-type-heading-3-line-height);
                                    font-weight:var(--mds-font-weight-bold);font-variant-numeric:tabular-nums;
                                    color:var(--mds-color-text-heading)">{{ form.drafts }}</dd>
                            </div>
                            <div style="display:flex;flex-direction:column-reverse;gap:2px;min-width:0">
                                <dt style="font-size:var(--mds-type-caption-font-size);
                                    font-weight:var(--mds-font-weight-semibold);
                                    letter-spacing:var(--mds-tracking-wide);text-transform:uppercase;
                                    color:var(--mds-color-text-secondary)">Last</dt>
                                <dd style="margin:0;font-size:var(--mds-type-body-md-font-size);
                                    line-height:var(--mds-type-heading-3-line-height);
                                    font-weight:var(--mds-font-weight-medium);
                                    color:var(--mds-color-text-heading)">{{ form.last }}</dd>
                            </div>
                        </dl>

                        <div v-if="form.capacity" style="display:flex;flex-direction:column;
                                    gap:var(--mds-space-1);margin-top:var(--mds-space-3)">
                            <span style="display:flex;justify-content:space-between;gap:var(--mds-space-2);
                                  font-size:var(--mds-type-body-sm-font-size);
                                  color:var(--mds-color-text-secondary)">
                                Capacity
                                <b style="color:var(--mds-color-text-body);font-variant-numeric:tabular-nums">
                                    {{ form.capacity.used }} / {{ form.capacity.cap }}</b>
                            </span>
                            <span role="progressbar" :aria-valuenow="form.capacity.used" :aria-valuemin="0"
                                  :aria-valuemax="form.capacity.cap"
                                  :aria-label="'Capacity: ' + form.capacity.used + ' of ' + form.capacity.cap + ' responses'"
                                  style="display:block;height:5px;border-radius:var(--mds-radius-full);
                                         background:var(--mds-color-bg-sunken);overflow:hidden">
                                <i :style="{
                                    display: 'block', height: '100%',
                                    width: Math.round(form.capacity.used / form.capacity.cap * 100) + '%',
                                    borderRadius: 'var(--mds-radius-full)',
                                    background: form.capacity.used / form.capacity.cap >= 0.75
                                        ? 'var(--mds-color-status-warning-fg)'
                                        : 'var(--mds-color-action-primary-bg)',
                                }" />
                            </span>
                        </div>
                        <p v-else style="margin:var(--mds-space-3) 0 0;
                                  font-size:var(--mds-type-body-sm-font-size);
                                  color:var(--mds-color-text-secondary)">{{ form.schedule }}</p>

                        <template #footer>
                            <div style="display:flex;align-items:center;gap:var(--mds-space-2);flex-wrap:wrap">
                                <span style="margin-right:auto;font-size:var(--mds-type-caption-font-size);
                                      color:var(--mds-color-text-secondary)">Updated 2 hours ago</span>
                                <div style="display:inline-flex;gap:var(--mds-space-1);flex-wrap:wrap;
                                            row-gap:var(--mds-space-1);justify-content:flex-end">
                                    <IconButton icon="layout" label="Open builder" size="sm" />
                                    <IconButton icon="chart-bar" label="Response statistics" size="sm" />
                                    <IconButton icon="submissions" label="New submission" size="sm" />
                                    <IconButton icon="clock" label="Version history" size="sm" />
                                    <IconButton icon="copy" label="Save as template" size="sm" />
                                    <IconButton icon="edit" label="Rename form" size="sm" />
                                    <IconButton icon="building" label="Set form scope" size="sm" />
                                    <IconButton icon="check" label="Publish form" size="sm" />
                                    <IconButton icon="trash" label="Archive form" variant="danger" size="sm" />
                                </div>
                            </div>
                        </template>
                    </Card>
                </li>
            </ul>
        </main>
    `,
});

const meta: Meta = { title: 'Composed/Card Grid', render };

export default meta;

type Story = StoryObj<typeof meta>;

export const Light: Story = {};

export const Dark: Story = { decorators: [dark] };
