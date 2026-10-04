/**
 * The Automations section (M132, `R-b7bc5149`), through the rendered DOM, with `fetch` stubbed at the boundary.
 *
 * What it has to get right: a web address is offered only to a reader the server says may add one; a row this reader
 * cannot change says who can; a new secret is shown once and goes away; and the section never says "step".
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AutomationsPanel from './AutomationsPanel.vue';
import { parseRecipients, runReason } from './automations';
import type { AutomationRow, AutomationsProps } from '@/components/forms/types';

const FORM_ID = 'form-1';

function emailRow(over: Partial<AutomationRow> = {}): AutomationRow {
    return {
        id: 'auto-1',
        name: 'Tell the team',
        action: 'email',
        enabled: true,
        recipients: ['nurse@example.org'],
        url: null,
        host: null,
        manageable: true,
        runs: [],
        ...over,
    };
}

function hookRow(over: Partial<AutomationRow> = {}): AutomationRow {
    return emailRow({ id: 'auto-2', name: 'To the registry', action: 'webhook', recipients: null, url: 'https://hooks.example.org/x', host: 'hooks.example.org', ...over });
}

function mountPanel(automations: Partial<AutomationsProps> = {}): VueWrapper {
    return mount(AutomationsPanel, {
        props: { open: true, formId: FORM_ID, automations: { can_webhook: true, max: 10, items: [], ...automations } },
    });
}

function respond(body: unknown, status = 200): Promise<Response> {
    return Promise.resolve(new Response(body === null ? null : JSON.stringify(body), { status }));
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('what the section offers', () => {
    it('offers a web address only where the server says this reader may add one', () => {
        const segments = (wrapper: VueWrapper) => wrapper.findAll('.mds-segmented__seg').map((segment) => segment.text());

        expect(segments(mountPanel({ can_webhook: true }))).toEqual(['Send an email', 'Send the answers to a web address']);
        expect(segments(mountPanel({ can_webhook: false }))).toEqual(['Send an email']);
    });

    it('summarises each automation, and says who can change one this reader cannot', () => {
        const wrapper = mountPanel({ items: [emailRow(), hookRow({ url: null, manageable: false })] });

        expect(wrapper.find('[data-automation="auto-1"]').text()).toContain('Emails nurse@example.org with a link to each new response.');
        const hook = wrapper.find('[data-automation="auto-2"]');
        expect(hook.text()).toContain('Sends the answers to hooks.example.org.');
        expect(hook.text()).toContain('Only Owners and Admins can change an automation that sends answers to a web address.');
        expect(hook.findAll('button').map((b) => b.text())).not.toContain('Delete');
    });

    it('stops offering an automation at the cap', () => {
        const rows = Array.from({ length: 10 }, (_, i) => emailRow({ id: `a${i}`, name: `Notice ${i}` }));
        const wrapper = mountPanel({ items: rows });

        expect(wrapper.text()).toContain('A form can have at most 10 automations.');
        expect(wrapper.findAll('button').map((b) => b.text())).not.toContain('Add automation');
    });

    it('never calls anything a "step"', () => {
        const wrapper = mountPanel({ items: [emailRow({ runs: [{ id: 'r', status: 'failed', label: 'Failed', at: null, response_status: 500, error_code: 'http_500' }] }), hookRow()] });

        expect(/\bstep\b/i.test(wrapper.text())).toBe(false);
    });
});

describe('adding', () => {
    it('adds an email automation with the addresses as typed, and lists it', async () => {
        const fetchMock = vi.fn(() => respond({ data: emailRow({ recipients: ['a@example.org', 'b@example.org'] }), secret: null }, 201));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mountPanel();

        await wrapper.find('.automations__add input').setValue('Tell the team');
        await wrapper.find('.automations__add textarea').setValue('a@example.org, b@example.org');
        await wrapper.findAll('button').find((b) => b.text() === 'Add automation')!.trigger('click');
        await flushPromises();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe(`/forms/${FORM_ID}/automations`);
        expect(JSON.parse(init.body as string)).toEqual({ name: 'Tell the team', action: 'email', recipients: ['a@example.org', 'b@example.org'] });
        expect(wrapper.find('[data-automation="auto-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-automation-secret]').exists()).toBe(false);
    });

    it('shows a web address’s new secret once, until the author says it is copied', async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond({ data: hookRow(), secret: 'whsec_abc123' }, 201)));
        const wrapper = mountPanel();

        await wrapper.find('.automations__add input').setValue('To the registry');
        await wrapper.findAll('.mds-segmented__input')[1].trigger('change');
        await wrapper.find('.automations__add input[type="url"]').setValue('https://hooks.example.org/x');
        await wrapper.findAll('button').find((b) => b.text() === 'Add automation')!.trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-automation-secret]').text()).toContain('whsec_abc123');
        await wrapper.findAll('button').find((b) => b.text() === 'I have copied it')!.trigger('click');
        expect(wrapper.find('[data-automation-secret]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('whsec_abc123');
    });

    it("shows the server's reason when an automation is refused, and lists nothing new", async () => {
        vi.stubGlobal('fetch', vi.fn(() => respond({ message: 'Invalid.', errors: { 'recipients.0': ['Each address must be a valid email address.'] } }, 422)));
        const wrapper = mountPanel();

        await wrapper.find('.automations__add input').setValue('Tell the team');
        await wrapper.find('.automations__add textarea').setValue('not an address');
        await wrapper.findAll('button').find((b) => b.text() === 'Add automation')!.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Each address must be a valid email address.');
        expect(wrapper.findAll('[data-automation]')).toHaveLength(0);
    });
});

describe('reading the runs', () => {
    it('says why a run did not succeed, in a sentence', () => {
        expect(runReason('http_500', 500)).toBe('The address answered 500.');
        expect(runReason('blocked_url', null)).toBe('The address points into a private network, so it was not called.');
        expect(runReason('plan_feature', null)).toBe("The workspace's plan does not include webhooks.");
        expect(runReason(null, 200)).toBeNull();
    });

    it('reads addresses typed with commas, semicolons or new lines', () => {
        expect(parseRecipients(' a@example.org,b@example.org;\nc@example.org ,, ')).toEqual(['a@example.org', 'b@example.org', 'c@example.org']);
    });
});
