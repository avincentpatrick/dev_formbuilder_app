import { describe, expect, it } from 'vitest';
import { EVENT_VARIANT, auditEventVariant } from './event-variant';

/**
 * The audit-event colour bands (Increment I2).
 *
 * Written as eight literal assertions rather than a loop over `Object.keys`, because a loop can only test
 * what is already in the map — it proves the map agrees with itself and nothing else.
 */
describe('auditEventVariant', () => {
    it('does NOT reuse the form-status colour for `archived`', () => {
        // THE REASON THIS MODULE EXISTS, and the one line most likely to be "simplified" away.
        // `statusVariant('archived')` is `warning`, correctly: a form in that STATE is inert and that is a
        // condition to notice. An audit EVENT saying someone archived something last March is an ordinary
        // historical fact. Without this assertion, someone deletes event-variant.ts, swaps in
        // statusVariant, and every gate in the repo stays green while the page turns amber.
        expect(auditEventVariant('archived')).toBe('neutral');
    });

    it('keeps the majority event quiet', () => {
        // `updated` is most of the ledger. Give it a colour and the page is a rainbow in which nothing
        // stands out — which costs exactly the two events below their meaning.
        expect(auditEventVariant('updated')).toBe('neutral');
    });

    it('reserves the attention band for the two consequential events', () => {
        expect(auditEventVariant('exported')).toBe('warning');
        expect(auditEventVariant('permission_changed')).toBe('warning');
    });

    it('maps the remaining events to their bands', () => {
        expect(auditEventVariant('created')).toBe('success');
        expect(auditEventVariant('published')).toBe('success');
        expect(auditEventVariant('restored')).toBe('info');
        expect(auditEventVariant('deleted')).toBe('danger');
    });

    it('falls back to neutral for an unknown event, and never throws', () => {
        // A PHP-side enum addition reaches this map as an unrecognised string. It must render as an
        // ordinary row rather than crashing the one page a compliance reader opens.
        expect(auditEventVariant('quarantined')).toBe('neutral');
        expect(auditEventVariant('')).toBe('neutral');
    });

    it('covers exactly the eight AuditEvent values', () => {
        // The other half of this pair lives in PHP: nothing here can see a new enum case, so a Pest test
        // asserting AuditEvent::cases() reaches the filter catalog is what catches the addition itself.
        expect(Object.keys(EVENT_VARIANT).sort()).toEqual([
            'archived',
            'created',
            'deleted',
            'exported',
            'permission_changed',
            'published',
            'restored',
            'updated',
        ]);
    });
});
