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

describe('statusVariant — feedback triage tokens (I7a)', () => {
    it('mirrors the submission review lifecycle, because it is the same shape', () => {
        expect(statusVariant('new')).toEqual({ variant: 'info', label: 'New' });
        expect(statusVariant('reviewed')).toEqual({ variant: 'warning', label: 'Reviewed' });
        expect(statusVariant('resolved')).toEqual({ variant: 'success', label: 'Resolved' });
    });

    it("colours wont_fix neutral, not danger — a settled decision is not a failure", () => {
        expect(statusVariant('wont_fix')).toEqual({ variant: 'neutral', label: "Won't fix" });
    });

    it('does not disturb any status the four feedback words could have collided with', () => {
        // `new`/`resolved`/`reviewed`/`wont_fix` are all first uses. If a later increment adds a status
        // that shares one of these words, this is where the shared-descriptor decision gets made.
        expect(statusVariant('submitted')).toEqual({ variant: 'info', label: 'Submitted' });
        expect(statusVariant('approved')).toEqual({ variant: 'success', label: 'Approved' });
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

describe('statusVariant — submission screen-out (I9a)', () => {
    it('colours screened_out neutral, not danger — nothing went wrong', () => {
        // Same rule as `wont_fix`/`disabled`/`revoked`: a settled non-failure is neutral. The respondent
        // finalized a form that had no questions left to show them; red would read as "this response errored".
        expect(statusVariant('screened_out')).toEqual({ variant: 'neutral', label: 'Screened out' });
    });

    it('would otherwise have rendered a pill labelled with the raw enum value', () => {
        // Why the entry is not optional. `statusVariant` never throws — it falls back to the raw string — so a
        // missing map entry is a SILENT defect: a neutral badge reading literally "screened_out" in front of a
        // customer. This asserts the fallback is what the map is standing in front of.
        expect(statusVariant('screened_out_typo')).toEqual({ variant: 'neutral', label: 'screened_out_typo' });
    });

    it('leaves the statuses screened_out sits between untouched', () => {
        expect(statusVariant('submitted')).toEqual({ variant: 'info', label: 'Submitted' });
        expect(statusVariant('under_review')).toEqual({ variant: 'warning', label: 'Under review' });
    });
});
