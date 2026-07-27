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
