/**
 * The Share panel through the rendered DOM (Increment I1).
 *
 * The load-bearing group is "when the link is not live". GuestFormController answers 404 to all three of
 * "no such slug", "guest access off" and "not published" — deliberately, so a slug-prober cannot tell them
 * apart. The author is owed the opposite, and a panel that showed a copyable URL in any of those states
 * would be handing them a link that fails silently. So the assertions are mostly NEGATIVE: no link, no QR,
 * no embed snippet, and a sentence naming the gate that is shut.
 *
 * The second group pins that the URL is never composed in the browser. Every absolute address here comes
 * from a server prop built by TenantUrl's PUBLIC arm; a custom-domain tenant gets a different host from the
 * one the admin app is served on, so anything derived from `window.location` would be wrong for exactly the
 * tenants who paid for it.
 */

import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe as group, expect, it, vi } from 'vitest';

import ShareModal from './ShareModal.vue';
import type { ShareProps } from '@/components/builder/types';

// `reactive` is load-bearing, not tidiness: Inertia's real useForm returns a reactive object, and a plain
// one here would leave every computed in the component frozen at its first value — the preview and the
// rename warning would silently never update and three tests would fail for a reason that has nothing to do
// with the component.
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
            patch: vi.fn(),
        }),
}));

/** A tenant on the default app host, form published and open — the fully-live state. */
function live(overrides: Partial<ShareProps> = {}): ShareProps {
    return {
        public_slug: 'clinic-intake',
        allow_guest_submissions: true,
        suggested_slug: 'clinic-intake',
        is_published: true,
        public_host: 'acme.meridian.test',
        public_url: 'https://acme.meridian.test/f/clinic-intake',
        ...overrides,
    };
}

function mountModal(share: ShareProps) {
    return mount(ShareModal, {
        props: { open: true, formId: 'form-1', formTitle: 'Clinic Intake', share },
        global: { stubs: { teleport: true } },
    });
}

group('when the link is live', () => {
    it('shows the public URL, the QR and the embed snippet', () => {
        const wrapper = mountModal(live());
        const text = wrapper.text();

        expect(text).toContain('https://acme.meridian.test/f/clinic-intake');
        expect(wrapper.find('img.share__qr').attributes('src')).toBe('/forms/form-1/share/qr.svg');
        expect(text).toContain('<iframe src="https://acme.meridian.test/f/clinic-intake"');
    });

    it('builds an embed snippet that carries a title and no border', () => {
        // The title attribute is the iframe's accessible name — without it a screen-reader user on the
        // EMBEDDER's page meets an unlabelled frame, which is a WCAG failure we would be shipping into
        // someone else's site.
        const snippet = mountModal(live()).text();

        expect(snippet).toContain('title="Clinic Intake"');
        expect(snippet).toContain('style="border:0"');
        expect(snippet).toContain('loading="lazy"');
    });

    it('escapes the form title into the embed snippet attribute', () => {
        // The snippet is pasted into SOMEONE ELSE'S page. A quote in a tenant-authored title would break the
        // tag; a deliberately chosen one could close the attribute and add its own, making one member's form
        // title an injection into a colleague's website.
        const wrapper = mount(ShareModal, {
            props: {
                open: true,
                formId: 'form-1',
                formTitle: '" onload="alert(1)',
                share: live(),
            },
            global: { stubs: { teleport: true } },
        });

        expect(wrapper.text()).toContain('title="&quot; onload=&quot;alert(1)"');
        expect(wrapper.text()).not.toContain('onload="alert(1)"');
    });

    it('offers an email link carrying the public URL', () => {
        const href = mountModal(live()).find('a[href^="mailto:"]').attributes('href') ?? '';

        expect(decodeURIComponent(href)).toContain('https://acme.meridian.test/f/clinic-intake');
        expect(decodeURIComponent(href)).toContain('Clinic Intake');
    });
});

group('when the link is not live', () => {
    it('says the form is unpublished and offers no live affordances', () => {
        const wrapper = mountModal(live({ is_published: false }));

        expect(wrapper.text()).toContain('no published version');
        // The read-only address preview DOES stay — an author setting up a form needs to know what the
        // address will be, and it carries no copy button, so it cannot be mistaken for a working link.
        // What must be absent is everything that implies the link works: the copy control, the QR (which
        // would encode a URL that 404s onto a printed flyer) and the embed snippet.
        expect(wrapper.find('.share__preview').text()).toContain('acme.meridian.test/f/clinic-intake');
        expect(wrapper.find('[aria-label="Copy link"]').exists()).toBe(false);
        expect(wrapper.find('img.share__qr').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('<iframe');
    });

    it('says guest responses are off and offers no link', () => {
        const wrapper = mountModal(live({ allow_guest_submissions: false }));

        expect(wrapper.text()).toContain('Guest responses are turned off');
        expect(wrapper.find('img.share__qr').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('<iframe');
    });

    it('says there is no link yet when the slug is null', () => {
        const wrapper = mountModal(
            live({ public_slug: null, allow_guest_submissions: false, public_url: null }),
        );

        expect(wrapper.text()).toContain('no link yet');
        expect(wrapper.find('img.share__qr').exists()).toBe(false);
    });

    it('reports the unpublished gate BEFORE the guest-access gate', () => {
        // Both are shut. Publishing is the one the author must do first — telling them to flip a toggle
        // that will still leave the link dead is worse than saying nothing.
        const wrapper = mountModal(live({ is_published: false, allow_guest_submissions: false }));

        expect(wrapper.text()).toContain('no published version');
        expect(wrapper.text()).not.toContain('Guest responses are turned off');
    });
});

group('the slug editor', () => {
    it('shows the server-supplied host as a fixed prefix', () => {
        // Never `window.location`: a Business tenant on a custom domain is served the admin app on one host
        // and their forms on another, so a browser-derived prefix would be wrong for them alone.
        const wrapper = mountModal(live({ public_host: 'forms.acme-example.com' }));

        expect(wrapper.find('.share__prefix').text()).toBe('forms.acme-example.com/f/');
    });

    it('previews the address for a slug that has not been saved yet', async () => {
        const wrapper = mountModal(live({ public_slug: null, allow_guest_submissions: false, public_url: null }));

        await wrapper.find('input.mds-input').setValue('new-intake');

        expect(wrapper.find('.share__preview').text()).toContain('acme.meridian.test/f/new-intake');
    });

    it('warns that renaming breaks links already shared', async () => {
        const wrapper = mountModal(live());

        expect(wrapper.find('.share__warn').exists()).toBe(false);

        await wrapper.find('input.mds-input').setValue('clinic-intake-2026');

        expect(wrapper.find('.share__warn').text()).toContain('breaks any copy of the old address');
    });

    it('does not warn about a rename on a form that never had a link', async () => {
        const wrapper = mountModal(live({ public_slug: null, allow_guest_submissions: false, public_url: null }));

        await wrapper.find('input.mds-input').setValue('first-link');

        expect(wrapper.find('.share__warn').exists()).toBe(false);
    });
});
