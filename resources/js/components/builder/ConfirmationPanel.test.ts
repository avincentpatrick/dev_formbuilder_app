import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

import ConfirmationPanel from './ConfirmationPanel.vue';
import type { RedirectTargetOption } from '@/components/forms/types';

/**
 * M130 (`R-db169c29`, `D76`) — the thank-you section's second half: where a respondent goes next.
 *
 * The mock records what the panel would PATCH after its own `transform()`, because that payload is the contract
 * with `UpdateConfirmationMessageRequest`: the kind always travels with a save, only the field that kind uses
 * travels with it, and "Reset message to default" sends no kind at all — which is what tells the server to leave
 * the destination as it is.
 */
const mocks = vi.hoisted(() => ({ patched: [] as Array<{ url: string; data: Record<string, unknown> }> }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        let transformer = (data: Record<string, unknown>): Record<string, unknown> => data;
        const form = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            clearErrors: () => {},
            transform(fn: (data: Record<string, unknown>) => Record<string, unknown>) {
                transformer = fn;
                return form;
            },
            patch(url: string) {
                const data = Object.fromEntries(Object.keys(initial).map((key) => [key, (form as Record<string, unknown>)[key]]));
                mocks.patched.push({ url, data: transformer(data) });
            },
        });
        return form;
    },
}));

const TARGETS: RedirectTargetOption[] = [
    { id: 'form-2', title: 'Follow-up visit', live: true },
    { id: 'form-3', title: 'Draft survey', live: false },
];

function panel(overrides: Record<string, unknown> = {}) {
    mocks.patched.length = 0;
    return mount(ConfirmationPanel, {
        props: {
            open: true,
            formId: 'form-1',
            form: {
                confirmation_message: 'Thanks!',
                confirmation_message_translations: {},
                redirect_kind: 'none',
                redirect_form_id: null,
                redirect_url: null,
                redirect_targets: TARGETS,
                redirect_delay_seconds: 20,
                theme_preset: null,
                theme_presets: [],
                default_locale: 'en',
                supported_locales: ['en'],
                ...overrides,
            },
        },
    });
}

function radio(wrapper: ReturnType<typeof panel>, label: string) {
    return wrapper.findAll('label.mds-radio').find((l) => l.text() === label)!.find('input');
}

async function save(wrapper: ReturnType<typeof panel>, name: string) {
    await wrapper.findAll('button').find((b) => b.text() === name)!.trigger('click');
    return mocks.patched.at(-1)!;
}

describe('ConfirmationPanel — where a respondent goes after the thank-you (M130)', () => {
    it('seeds the destination the server holds, and offers only the forms it was given', () => {
        const wrapper = panel({ redirect_kind: 'form', redirect_form_id: 'form-2' });

        expect((radio(wrapper, 'Go to another form').element as HTMLInputElement).checked).toBe(true);
        const select = wrapper.find('select');
        expect((select.element as HTMLSelectElement).value).toBe('form-2');
        expect(select.findAll('option').map((o) => o.text())).toEqual([
            'Choose a form',
            'Follow-up visit',
            'Draft survey (not open to respondents)',
        ]);
    });

    it('saves the kind with the message, and only the field that kind uses', async () => {
        const wrapper = panel();

        await radio(wrapper, 'Go to a web address').setValue(true);
        await wrapper.find('input[type="url"]').setValue('https://health.example.org/next');
        const sent = await save(wrapper, 'Save thank-you screen');

        expect(sent.url).toBe('/forms/form-1/confirmation');
        expect(sent.data).toEqual({
            confirmation_message: 'Thanks!',
            confirmation_message_translations: null,
            redirect_kind: 'url',
            redirect_url: 'https://health.example.org/next',
            redirect_delay_seconds: 20,
        });

        await radio(wrapper, 'Go to another form').setValue(true);
        await wrapper.find('select').setValue('form-2');
        expect((await save(wrapper, 'Save thank-you screen')).data).toMatchObject({ redirect_kind: 'form', redirect_form_id: 'form-2' });
        expect(mocks.patched.at(-1)!.data).not.toHaveProperty('redirect_url');

        await radio(wrapper, 'Stay on the thank-you screen').setValue(true);
        const stay = (await save(wrapper, 'Save thank-you screen')).data;
        expect(stay).toMatchObject({ redirect_kind: 'none' });
        expect(stay).not.toHaveProperty('redirect_form_id');
        expect(stay).not.toHaveProperty('redirect_url');
    });

    it('resets the message alone, so a destination is never cleared by omission', async () => {
        const wrapper = panel({ redirect_kind: 'url', redirect_url: 'https://health.example.org/next' });

        const sent = await save(wrapper, 'Reset message to default');

        expect(sent.data).toEqual({ confirmation_message: null, confirmation_message_translations: null });
    });

    it('warns about a chosen form respondents cannot open yet, in the words the submit response acts on', () => {
        const wrapper = panel({ redirect_kind: 'form', redirect_form_id: 'form-3' });

        expect(wrapper.text()).toContain('This form is not open to respondents yet');
        expect(wrapper.text()).toContain('respondents stay on the thank-you screen');
    });

    it('keeps a saved destination this author cannot open, unnamed, and never resends it unchanged', async () => {
        // Set by a colleague who can open it. Shown as kept, not as the first form in the list (which a select
        // falls back to for a value it lacks), and left out of a save the author did not change it in — the
        // request would refuse the id on this author's behalf and block a message edit.
        const wrapper = panel({ redirect_kind: 'form', redirect_form_id: 'form-9' });
        const select = wrapper.find('select');

        expect(wrapper.text()).toContain('Respondents go to a form you cannot open.');
        expect((select.element as HTMLSelectElement).value).toBe('form-9');
        const element = select.element as HTMLSelectElement;
        expect(element.options[element.selectedIndex]?.text).toBe('A form you cannot open (kept as it is)');
        expect((await save(wrapper, 'Save thank-you screen')).data).toEqual({
            confirmation_message: 'Thanks!',
            confirmation_message_translations: null,
        });

        // Changing it is a destination like any other.
        await select.setValue('form-2');
        expect((await save(wrapper, 'Save thank-you screen')).data).toMatchObject({ redirect_kind: 'form', redirect_form_id: 'form-2' });
    });

    it('describes the group by what will happen, and shows no input for staying', () => {
        const wrapper = panel();
        const group = wrapper.find('fieldset[data-redirect-settings]');

        expect(group.find('legend').text()).toBe('After the thank-you screen');
        const help = wrapper.find(`#${group.attributes('aria-describedby')}`);
        expect(help.text()).toContain('after the wait you choose unless they choose to stay');
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('input[type="url"]').exists()).toBe(false);
    });

    it('offers the four waits beside a destination, says what under 20 costs, and sends the choice (M138, D91)', async () => {
        const wrapper = panel({ redirect_kind: 'url', redirect_url: 'https://health.example.org/next', redirect_delay_seconds: 10 });
        const delay = wrapper.find('select[data-redirect-delay]');

        expect(delay.findAll('option').map((o) => o.text())).toEqual(['5 seconds', '10 seconds', '20 seconds (recommended)', '30 seconds']);
        expect((delay.element as HTMLSelectElement).value).toBe('10');
        expect(wrapper.text()).toContain('less time than the accessibility guideline (WCAG 2.2.1) asks for');

        await delay.setValue('30');
        expect(wrapper.text()).not.toContain('WCAG 2.2.1');
        expect((await save(wrapper, 'Save thank-you screen')).data).toMatchObject({ redirect_kind: 'url', redirect_delay_seconds: 30 });

        // Staying has no wait to choose, and sends none.
        await radio(wrapper, 'Stay on the thank-you screen').setValue(true);
        expect(wrapper.find('select[data-redirect-delay]').exists()).toBe(false);
        expect((await save(wrapper, 'Save thank-you screen')).data).not.toHaveProperty('redirect_delay_seconds');
    });

    it('offers no wait for a kept destination this author cannot open, which travels as no destination at all', () => {
        const wrapper = panel({ redirect_kind: 'form', redirect_form_id: 'form-9' });

        expect(wrapper.find('select[data-redirect-delay]').exists()).toBe(false);
    });
});
