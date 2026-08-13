import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

/**
 * The Settings → Single sign-on → People and provisioning card (P1a, ADR-0016).
 *
 * TWO INVARIANTS WORTH A TEST, both invisible on screen when broken.
 *
 * (1) THE ROLE PICKER IS BUILT FROM THE PROP, NEVER FROM A LITERAL. `AssignableRoles` is the same expression
 * the `sso_connections_default_role_check` CHECK is compiled from, so a hard-coded option list here would
 * not fail at validation — it would fail as a SQLSTATE 23514 five hundred, which no static gate catches.
 *
 * (2) A PLAN-DENIED CARD OFFERS NO UPGRADE CTA. ADR-0008 §D6 seeds Enterprise `is_active: false`, so a
 * button pointing at it would point at a plan nobody can buy. The card states the fact and stops — and
 * "there is no Upgrade button" is precisely the kind of absence a reviewer stops noticing.
 */
const mocks = vi.hoisted(() => ({
    patch: vi.fn(),
    form: null as unknown as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    // `reactive`, not a plain object: useForm's return is reactive in production, and a frozen stand-in
    // makes `form.recentlySuccessful` unable to ever change — the ShareModal.test.ts lesson.
    useForm: (initial: Record<string, unknown>) => {
        mocks.form = reactive({
            ...initial,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            patch: mocks.patch,
            defaults: vi.fn(),
            reset: vi.fn(),
        });

        return mocks.form;
    },
}));

const SsoPolicyCard = (await import('./SsoPolicyCard.vue')).default;

function connection(overrides: Record<string, unknown> = {}) {
    return {
        status: 'active',
        status_label: 'Active',
        protocol_label: 'SAML 2.0',
        serves_protocol: true,
        idp_entity_id: 'https://idp.example.com/saml2',
        idp_sso_url: 'https://idp.example.com/saml2/sso',
        name_id_format: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        name_id_format_known: true,
        name_id_format_is_email: true,
        jit_provisioning_enabled: true,
        default_role_name: 'viewer',
        default_role_label: 'Viewer',
        attribute_map: {},
        fingerprint_short: 'a1b2c3d4e5f6',
        certificate_count: 1,
        certificates: [],
        certificates_state: 'ok',
        certificate_warning: null,
        metadata_imported_at: '2026-08-13T00:00:00+00:00',
        last_login_at: null,
        ...overrides,
    };
}

function render(overrides: Record<string, unknown> = {}) {
    return mount(SsoPolicyCard, {
        props: {
            connection: connection(),
            roles: [
                { value: 'admin', label: 'Admin' },
                { value: 'viewer', label: 'Viewer' },
            ],
            nameIdFormats: [
                { value: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress', label: 'Email address' },
            ],
            attributeKeys: ['email', 'name'],
            canConfigure: true,
            ...overrides,
        },
        global: { stubs: { MdsCard: { template: '<div><slot name="header" /><slot /></div>' } } },
    });
}

describe('SsoPolicyCard', () => {
    it('builds the role picker from the prop rather than a literal', () => {
        const wrapper = render();
        const values = wrapper
            .find('select[name="default_role_name"]')
            .findAll('option')
            .map((option) => option.attributes('value'));

        // Exactly the two the prop carried — a hard-coded four-option list would fail here. Asserted on the
        // OPTION VALUES rather than the card's text, because the help copy legitimately says "Owner is never
        // granted this way" and a text search would be satisfied by the wrong thing in both directions.
        expect(values).toEqual(['admin', 'viewer']);
        expect(values).not.toContain('owner');
    });

    it('offers no way to save, and no upgrade CTA, when the plan does not include SSO', () => {
        const wrapper = render({ canConfigure: false });

        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
        expect(wrapper.find('fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toMatch(/upgrade/i);
    });

    it('says plainly when the provider sends an identifier this application cannot resolve', () => {
        // config/saml.php requires the screen to state this: an IdP sending a persistent opaque identifier
        // will never match a user here, and left unsaid it presents as an intermittent login failure.
        const wrapper = render({
            connection: connection({
                name_id_format: 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                name_id_format_is_email: false,
            }),
        });

        expect(wrapper.text()).toContain('matches people by email address');
    });

    it('locks the format control when the stored value is not one the picker can represent', () => {
        // Silently re-selecting the nearest option would rewrite the tenant's contract with their IdP on
        // their next unrelated save.
        const wrapper = render({
            connection: connection({ name_id_format: 'urn:example:custom', name_id_format_known: false }),
        });

        expect(wrapper.find('select[name="name_id_format"]').attributes('disabled')).toBeDefined();
    });
});
