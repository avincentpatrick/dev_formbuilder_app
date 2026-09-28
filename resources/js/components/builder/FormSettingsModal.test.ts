/**
 * The builder's one Form settings surface, through the rendered DOM (Increment M117, decision `D63`).
 *
 * Three properties carry this file, and each of them is a thing that has actually gone wrong on this page:
 *
 *   1. ⛔ THE RAIL IS NOT A TABLIST. `Builder.vue` records that thirteen locators across `builder-axe`,
 *      `responsive-axe` and `personalization-axe` walk `[role="tab"]` on the builder, FOUR OF THEM LOOPS
 *      THAT CLICK EVERY MATCH, and `ConfigPanel.vue` records that this page holds exactly one tablist,
 *      permanently. A rail that grew a tab role would be clicked mid-scan by four existing specs and would
 *      fail somewhere else entirely, for a reason nothing would point at. The idiom is copied from
 *      `TabNav.test.ts`, which pins the same property on the hub strip for the same reason.
 *   2. THE SECTIONS ARE THE ROW'S CONTENT. `D63` answered "both entry points" on the promise that they
 *      mount the same children; a section quietly dropped here is a setting that becomes unreachable from
 *      the builder again, which is the whole complaint.
 *   3. THE GATED SECTIONS ARE ABSENT, NOT DISABLED. Save-and-resume follows its plan feature, and Share
 *      follows the absent-not-empty `share` prop. A rail row that cannot open is a dead end.
 *
 * ⚠️ WHY THE PANELS ARE NOT ASSERTED THROUGH THEIR SAVE CALLS HERE. Each section owns its route and its own
 * FormRequest, and the per-route payloads are pinned where they live — `ShareModal.test.ts` for the sharing
 * enum mapping, and the Pest feature tests for the rest. Re-asserting them through this shell would move
 * those assertions further from the code that makes them true.
 */

import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe as group, expect, it, vi } from 'vitest';

import FormSettingsModal from './FormSettingsModal.vue';
import type { ShareProps } from '@/components/forms/types';

// `reactive` is load-bearing for the same reason `ShareModal.test.ts` says it is: Inertia's real useForm
// returns a reactive object, and a plain one leaves every computed in the child panels frozen at its first
// value. `router` is stubbed because SaveResumePanel patches directly rather than through a form.
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            clearErrors: () => {},
            transform() {
                return this;
            },
            patch: () => {},
        }),
    router: { patch: () => {} },
}));

const share: ShareProps = {
    public_slug: 'clinic-intake',
    allow_guest_submissions: true,
    bot_challenge: 'off',
    guest_rate_limit_per_minute: null,
    suggested_slug: 'clinic-intake',
    is_published: true,
    public_url: 'https://acme.example.test/f/clinic-intake',
    public_host: 'acme.example.test',
} as ShareProps;

const form = {
    title: 'Clinic intake',
    description: null,
    confirmation_message: null,
    confirmation_message_translations: {},
    default_locale: 'en',
    supported_locales: ['en'],
    opens_at: null,
    closes_at: null,
    timezone: 'UTC',
    max_responses: null,
    save_and_resume: false,
};

function mountModal(over: { share?: ShareProps | null; saveResumeAvailable?: boolean } = {}) {
    return mount(FormSettingsModal, {
        props: {
            open: true,
            formId: 'form-1',
            form,
            timezones: ['UTC', 'Asia/Manila'],
            share: over.share === undefined ? share : over.share,
            saveResumeAvailable: over.saveResumeAvailable ?? true,
            // Render in place rather than teleporting, so the assertions can see the panel — the same
            // reason `Modal.stories.ts` passes it for the axe runner.
            teleport: false,
        },
        global: { stubs: { teleport: true } },
    });
}

/** The rail's buttons, in DOM order. */
function railLabels(wrapper: ReturnType<typeof mountModal>): string[] {
    return wrapper.findAll('.form-settings__rail-button').map((b) => b.text());
}

group('FormSettingsModal — the rail', () => {
    it('uses NO tab/tablist role anywhere, which is the whole reason it is a local control', () => {
        // Mutation: give `.form-settings__rail` role="tablist" and its buttons role="tab". Reddens here and
        // — far more expensively — sends four existing e2e loops clicking into this modal.
        const wrapper = mountModal();
        const html = wrapper.html();

        expect(html).not.toContain('tablist');
        expect(wrapper.find('[role="tab"]').exists()).toBe(false);
        expect(wrapper.find('[aria-selected]').exists()).toBe(false);

        // ⚠️ AND NOT `radiogroup` EITHER. A radiogroup owes arrow-key roving that these buttons do not
        // implement; claiming the role without the behaviour is worse than not claiming it. `group` plus
        // per-button `aria-pressed` is what the page's own `.builder__left-tabs` precedent uses.
        expect(html).not.toContain('radiogroup');
        expect(wrapper.find('[role="group"]').attributes('aria-label')).toBe('Settings section');

        wrapper.unmount();
    });

    it('offers exactly the five sections, and names none of them after a builder pane', () => {
        const wrapper = mountModal();

        expect(railLabels(wrapper)).toEqual([
            'Details',
            'Share',
            'Schedule',
            'Thank-you message',
            'Save and finish later',
        ]);

        // ⛔ THE WORD "SETTINGS" IS ALREADY TAKEN ON THIS PAGE, and so are "Add" and "Form": they label the
        // compact pane switcher, whose options `tests/e2e/support/navigate.ts` resolves by exact text. A
        // rail label repeating one of them makes `showBuilderPane()` ambiguous the moment a spec stops
        // scoping — which is exactly the failure `builder-layout.test.ts` pins on the switcher's own label.
        for (const paneWord of ['Add', 'Form', 'Settings']) {
            expect(railLabels(wrapper)).not.toContain(paneWord);
        }

        wrapper.unmount();
    });

    it('marks exactly one section pressed, and moves it on selection', async () => {
        const wrapper = mountModal();
        const pressed = () =>
            wrapper
                .findAll('.form-settings__rail-button')
                .filter((b) => b.attributes('aria-pressed') === 'true')
                .map((b) => b.text());

        expect(pressed()).toEqual(['Details']);

        await wrapper.findAll('.form-settings__rail-button')[2].trigger('click');
        expect(pressed()).toEqual(['Schedule']);

        wrapper.unmount();
    });
});

group('FormSettingsModal — mounting', () => {
    it('mounts a section on first visit and KEEPS it mounted, so a switch discards nothing typed', async () => {
        // ⛔ BOTH HALVES ARE THE ASSERTION. Not eager, because SharePanel renders the QR as an <img src> and
        // mounting it would fetch the SVG for an author who opened settings to change the title. Not
        // v-if-only, because unmounting on a switch silently throws away what they typed in the section
        // they just left — and a settings surface where leaving a tab loses work is worse than five dialogs.
        const wrapper = mountModal();

        expect(wrapper.find('.share').exists()).toBe(false);

        await wrapper.findAll('.form-settings__rail-button')[1].trigger('click');
        expect(wrapper.find('.share').exists()).toBe(true);

        // …and it survives navigating away.
        await wrapper.findAll('.form-settings__rail-button')[0].trigger('click');
        expect(wrapper.find('.share').exists()).toBe(true);

        // ⚠️ THE STYLE, NOT `isVisible()`. Measured: with the Share section hidden its wrapper carries
        // `display: none` exactly as `v-show` should, and `find('.share').isVisible()` still reported TRUE —
        // the helper's ancestor walk does not cross the modal's teleport boundary. Asserting the attribute
        // `v-show` actually writes is both the honest check and the one that cannot pass for a wrong reason.
        expect(wrapper.find('[data-section="share"]').attributes('style')).toContain('display: none');
        expect(wrapper.find('[data-section="general"]').attributes('style') ?? '').not.toContain('display: none');

        wrapper.unmount();
    });
});

group('FormSettingsModal — gated sections', () => {
    it('drops save-and-resume from the rail when the plan does not include it', () => {
        const wrapper = mountModal({ saveResumeAvailable: false });

        expect(railLabels(wrapper)).not.toContain('Save and finish later');
        expect(railLabels(wrapper)).toHaveLength(4);

        wrapper.unmount();
    });

    it('drops Share when the server does not offer it, and still opens on a real section', () => {
        // Absent, not empty — the convention `Show.vue` uses for this prop. The first rail entry must still
        // be the one that is selected, which is why the default is computed rather than hard-coded.
        const wrapper = mountModal({ share: null });

        expect(railLabels(wrapper)).not.toContain('Share');
        expect(wrapper.find('.share').exists()).toBe(false);
        expect(
            wrapper
                .findAll('.form-settings__rail-button')
                .filter((b) => b.attributes('aria-pressed') === 'true')
                .map((b) => b.text()),
        ).toEqual(['Details']);

        wrapper.unmount();
    });
});
