import { describe, expect, it } from 'vitest';
import { statusVariant } from './status-variant';

describe('statusVariant — webhook tokens (H14)', () => {
    it('resolves webhook endpoint statuses to the right descriptor', () => {
        // `active` is shared with the member/tenant lifecycle — a green success pill.
        expect(statusVariant('active')).toEqual({ variant: 'success', label: 'Active' });
        // Breaker auto-pause needs attention → amber; a manual/platform disable is inert → neutral.
        expect(statusVariant('paused')).toEqual({ variant: 'warning', label: 'Paused' });
        expect(statusVariant('disabled')).toEqual({ variant: 'neutral', label: 'Disabled' });
    });

    it('resolves webhook delivery statuses, distinguishing transient failure from terminal', () => {
        expect(statusVariant('pending')).toEqual({ variant: 'neutral', label: 'Pending' });
        expect(statusVariant('delivering')).toEqual({ variant: 'info', label: 'Delivering' });
        expect(statusVariant('succeeded')).toEqual({ variant: 'success', label: 'Succeeded' });
        // `failed` still has retries pending → amber; `dead_lettered` is terminal → red.
        expect(statusVariant('failed')).toEqual({ variant: 'warning', label: 'Failed' });
        expect(statusVariant('dead_lettered')).toEqual({ variant: 'danger', label: 'Dead-lettered' });
    });

    it('falls back to a neutral, raw-value-labelled pill for an unknown status (never throws)', () => {
        expect(statusVariant('quantum')).toEqual({ variant: 'neutral', label: 'quantum' });
    });
});

describe('statusVariant — native-connector tokens (H15b)', () => {
    it('labels a connection by what the tenant must do, not by the enum word', () => {
        expect(statusVariant('refresh_failed')).toEqual({ variant: 'danger', label: 'Reconnect needed' });
        expect(statusVariant('revoked')).toEqual({ variant: 'neutral', label: 'Disconnected' });
    });

    it('reuses the shared descriptors for the statuses connectors have in common with webhooks', () => {
        // ConnectorSubscriptionStatus is active/paused/disabled — the same three words the webhook endpoint
        // lifecycle already owns, so a rule and an endpoint must never render the same word two ways.
        expect(statusVariant('active')).toEqual({ variant: 'success', label: 'Active' });
        expect(statusVariant('paused')).toEqual({ variant: 'warning', label: 'Paused' });
        expect(statusVariant('disabled')).toEqual({ variant: 'neutral', label: 'Disabled' });
    });
});

describe('statusVariant — custom-domain tokens (H22b)', () => {
    it('does NOT label a verified domain "Verified", because it does not serve anything yet', () => {
        // The single most consequential label in this map. ADR-0012 §D6 leaves activation to an operator
        // who has installed a TLS certificate by hand, so a domain that has proven control still serves
        // nothing — and a tenant reading "Verified" beside its hostname will reasonably repoint live
        // traffic at an origin holding no certificate for it. INFO rather than SUCCESS for the same
        // reason: success would claim the job is finished.
        expect(statusVariant('verified')).toEqual({ variant: 'info', label: 'Awaiting setup' });
        expect(statusVariant('live')).toEqual({ variant: 'success', label: 'Live' });
    });

    it('reuses the shared neutral pending descriptor rather than coining a second one', () => {
        // The domain lifecycle's first state shares its word with webhook deliveries, exactly as
        // draft/archived are shared across the form and submission lifecycles.
        expect(statusVariant('pending')).toEqual({ variant: 'neutral', label: 'Pending' });
    });
});
