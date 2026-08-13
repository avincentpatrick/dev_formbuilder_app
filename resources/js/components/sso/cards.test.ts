import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

/**
 * Mount smoke tests for the SSO cards (P1a, ADR-0016).
 *
 * ⚠️ THIS FILE COVERS A GAP EVERY OTHER GATE IN THIS REPO LEAVES OPEN. The Pest suite asserts the Inertia
 * PROPS, not the rendered component; `vue-tsc` type-checks templates but never executes them; and the Vite
 * build compiles an SFC without ever mounting it. So a card that throws on mount — an undefined token read,
 * a `v-for` over something that is null in a legitimate state, a prop the parent does not actually pass —
 * ships as a blank page with every gate green. These cases do the one thing that catches that: render each
 * card in each state the presenter can produce.
 */
const mocks = vi.hoisted(() => ({ put: vi.fn(), patch: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            put: mocks.put,
            patch: mocks.patch,
            reset: vi.fn(),
            defaults: vi.fn(),
            clearErrors: vi.fn(),
        }),
}));

const SpDetailsCard = (await import('./SpDetailsCard.vue')).default;
const IdpMetadataCard = (await import('./IdpMetadataCard.vue')).default;
const SsoStatusCard = (await import('./SsoStatusCard.vue')).default;
const SsoFailuresCard = (await import('./SsoFailuresCard.vue')).default;

const stubs = { MdsCard: { template: '<div><slot name="header" /><slot /></div>' } };

function failure(overrides: Record<string, unknown> = {}) {
    return {
        id: '019ff000-0000-7000-8000-000000000001',
        reason: 'assertion_outside_conditions',
        reason_label: 'Response was outside its validity window',
        hint: 'The identity provider’s clock is out by more than the allowance. Check time synchronisation on that server.',
        subject_email: null,
        request_id: null,
        ip_address: '203.0.113.4',
        occurred_at: '2026-08-14T09:00:00+00:00',
        ...overrides,
    };
}

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
        certificates: [
            {
                subject: 'idp.example.com',
                issuer: 'idp.example.com',
                serial: '0A1B',
                thumbprint_short: 'abcdef012345',
                not_before: '2026-01-01T00:00:00+00:00',
                not_after: '2027-01-01T00:00:00+00:00',
                expires_in_days: 140,
                state: 'valid',
            },
        ],
        certificates_state: 'ok',
        certificate_warning: null,
        metadata_imported_at: '2026-08-13T00:00:00+00:00',
        last_login_at: null,
        ...overrides,
    };
}

describe('SpDetailsCard', () => {
    it('renders all four values an admin must copy, in every state', () => {
        // Shown even before anything is imported: the IdP has to be told about this SP first.
        const wrapper = mount(SpDetailsCard, {
            props: {
                sp: {
                    entity_id: 'http://acme.meridian.test/sso/saml/metadata',
                    acs_url: 'http://acme.meridian.test/sso/saml/acs',
                    metadata_url: 'http://acme.meridian.test/sso/saml/metadata',
                    login_url: 'http://acme.meridian.test/sso/saml/login',
                },
            },
            global: { stubs },
        });

        // Three for the identity provider, one (P1b) for the workspace's own people.
        expect(wrapper.findAll('code')).toHaveLength(4);
        expect(wrapper.text()).toContain('/sso/saml/acs');
        // The sign-in URL is currently the ONLY way into the flow — the login page's own button belongs to
        // the auth vertical another lane is rebuilding. If this row ever silently stops rendering, SSO
        // becomes unreachable with every other gate still green, which is precisely the mount-smoke gap
        // this file exists for.
        expect(wrapper.text()).toContain('/sso/saml/login');
    });
});

describe('IdpMetadataCard', () => {
    it('mounts with no connection and invites the first import', () => {
        const wrapper = mount(IdpMetadataCard, {
            props: { connection: null, canConfigure: true },
            global: { stubs },
        });

        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.text()).toContain('Paste the XML metadata document');
    });

    it('renders a stored certificate without ever rendering the certificate', () => {
        const wrapper = mount(IdpMetadataCard, {
            props: { connection: connection(), canConfigure: true },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('idp.example.com');
        expect(wrapper.text()).toContain('abcdef012345');
        // The prop type has no field that could carry a key; this is the runtime half of that guarantee.
        expect(wrapper.html()).not.toContain('BEGIN CERTIFICATE');
    });

    it('withholds the import form, and any upgrade CTA, from an unentitled tenant', () => {
        const wrapper = mount(IdpMetadataCard, {
            props: { connection: connection(), canConfigure: false },
            global: { stubs },
        });

        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(/upgrade/i);
    });

    it('renders every certificate state without throwing', () => {
        // Each state has its own badge tone and its own sentence; `unreadable` and `not_yet_valid` take
        // branches the happy path never reaches.
        for (const state of ['valid', 'expiring_soon', 'expired', 'not_yet_valid', 'unreadable'] as const) {
            const wrapper = mount(IdpMetadataCard, {
                props: {
                    connection: connection({
                        certificates: [{ ...connection().certificates[0], state }],
                        certificates_state: state === 'valid' ? 'ok' : state,
                        certificate_warning: state === 'valid' ? null : 'Re-import your metadata.',
                    }),
                    canConfigure: true,
                },
                global: { stubs },
            });

            expect(wrapper.html()).toBeTruthy();
        }
    });
});

describe('SsoStatusCard', () => {
    it('tells an active workspace that sign-in works and that passwords still do', () => {
        // ⚠️ THIS CASE USED TO ASSERT THE OPPOSITE, AND IT WENT ON PASSING AFTER IT STOPPED BEING TRUE.
        // P1a's banner said signing in with SSO "isn’t switched on yet", which was exact until P1b landed
        // the round trip — after which the product told every admin their working sign-in did not work, and
        // this test defended the wrong sentence. A test can only pin the copy; nothing can notice that a
        // statement about a NEIGHBOURING increment has expired. Corrected in P1c, recorded here.
        const wrapper = mount(SsoStatusCard, {
            props: { connection: connection(), canManage: true, canConfigure: true, busy: false },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('can sign in with your identity provider now');
        // The half that has not changed and must not: enabling SSO locks nobody out.
        expect(wrapper.text()).toContain('Email and password still work');
    });

    it('keeps the switch usable for a downgraded tenant, so they can still turn it off', () => {
        // The escape hatch at the UI layer: canConfigure false, canManage true, connection ON.
        const wrapper = mount(SsoStatusCard, {
            props: { connection: connection(), canManage: true, canConfigure: false, busy: false },
            global: { stubs },
        });

        // The rendered input, not findComponent — MdsSwitch registers no `name`, so a component lookup
        // returns an empty wrapper and `.props()` throws rather than failing the assertion.
        expect(wrapper.find('input[role="switch"]').attributes('disabled')).toBeUndefined();
    });

    it('does not offer to switch it back ON once downgraded', () => {
        const wrapper = mount(SsoStatusCard, {
            props: {
                connection: connection({ status: 'disabled', status_label: 'Disabled', serves_protocol: false }),
                canManage: true,
                canConfigure: false,
                busy: false,
            },
            global: { stubs },
        });

        expect(wrapper.find('input[role="switch"]').attributes('disabled')).toBeDefined();
    });

    it('mounts in the draft state, which has its own copy', () => {
        const wrapper = mount(SsoStatusCard, {
            props: {
                connection: connection({ status: 'draft', status_label: 'Draft', serves_protocol: false }),
                canManage: true,
                canConfigure: true,
                busy: false,
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Turn this on once you have added this workspace');
        expect(wrapper.text()).not.toContain('can sign in with your identity provider now');
    });
});

describe('SsoFailuresCard', () => {
    it('renders the good state as good, not as an empty list waiting to be filled', () => {
        // ⚠️ THE INVERSE OF EVERY OTHER EMPTY STATE IN THE PRODUCT. Elsewhere "nothing here" means "go and
        // make one"; here it means everything is working, and the copy has to say so or an admin reads a
        // healthy workspace as an unfinished one.
        const wrapper = mount(SsoFailuresCard, {
            props: { failures: [], serving: true },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('No failed sign-ins recorded');
    });

    it('says nothing has served yet while the connection is still draft', () => {
        const wrapper = mount(SsoFailuresCard, {
            props: { failures: [], serving: false },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('has not served a sign-in');
    });

    it('leads with the reason and follows it with something to actually do', () => {
        const wrapper = mount(SsoFailuresCard, {
            props: { failures: [failure()], serving: true },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Response was outside its validity window');
        // The hint is an instruction, not a restatement — the difference between an admin fixing NTP and
        // an admin hunting through a certificate they never touched.
        expect(wrapper.text()).toContain('Check time synchronisation');
        expect(wrapper.find('time').attributes('datetime')).toBe('2026-08-14T09:00:00+00:00');
    });

    it('omits the account row entirely when no signature vouched for an address', () => {
        // Null is the state for every PRE-validation refusal, and it is the common one. A row that rendered
        // "Account: —" would invite an admin to read an absence as a fact about the person.
        const wrapper = mount(SsoFailuresCard, {
            props: { failures: [failure()], serving: true },
            global: { stubs },
        });

        expect(wrapper.text()).not.toContain('Account');
    });

    it('shows the account and request id once there are any', () => {
        const wrapper = mount(SsoFailuresCard, {
            props: {
                failures: [
                    failure({
                        reason: 'membership_suspended',
                        reason_label: 'Membership is suspended',
                        subject_email: 'ada@acme.test',
                        request_id: '_0123456789abcdef0123456789abcdef',
                    }),
                ],
                serving: true,
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('ada@acme.test');
        expect(wrapper.text()).toContain('_0123456789abcdef0123456789abcdef');
    });
});
