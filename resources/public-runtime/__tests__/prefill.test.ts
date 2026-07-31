/**
 * Hidden-field prefill (Increment H7) — the pure `lib/prefill.ts` mapper, plus the store behaviour that
 * depends on it: the seeding ORDER (restored answers beat a prefill) and the fact that a hidden field is
 * dropped from the step model entirely.
 *
 * The PHP side of the same contract is pinned by `StructuralAnswerNormalizerTest` (source authority + the
 * byte cap) and `HiddenFieldPrefillTest` (the full vertical). What lives only here is the URL half — PHP
 * never sees a query string, it sees whatever the SPA submitted.
 */
import { describe, expect, it } from 'vitest';
import { buildPrefill, MAX_PREFILL_BYTES, prefillParamOf, prefillSourceOf } from '../lib/prefill';
import { createFormRuntime } from '../composables/useFormRuntime';
import { field, schemaResponse, section } from './fixtures';

const hidden = (key: string, config: Record<string, unknown> | null, extra: Record<string, unknown> = {}) =>
    field({ key, field_type: 'hidden', config, ...extra });

describe('prefillSourceOf / prefillParamOf', () => {
    it('reads a declared source only from a hidden field', () => {
        expect(prefillSourceOf(hidden('a', { prefill_source: 'url' }))).toBe('url');
        expect(prefillSourceOf(hidden('a', { prefill_source: 'fixed' }))).toBe('fixed');
        // The same config on a non-hidden field means nothing — the type gate comes first.
        expect(prefillSourceOf(field({ key: 'a', config: { prefill_source: 'url' } }))).toBeNull();
    });

    it('treats an absent or unrecognised source as none', () => {
        expect(prefillSourceOf(hidden('a', null))).toBeNull();
        expect(prefillSourceOf(hidden('a', {}))).toBeNull();
        expect(prefillSourceOf(hidden('a', { prefill_source: 'nonsense' }))).toBeNull();
        expect(prefillSourceOf(hidden('a', { prefill_source: 42 }))).toBeNull();
    });

    it('falls back to the field key when no parameter name is declared', () => {
        expect(prefillParamOf(hidden('promo', { prefill_source: 'url' }))).toBe('promo');
        expect(prefillParamOf(hidden('promo', { prefill_source: 'url', url_param: '  ' }))).toBe('promo');
        expect(prefillParamOf(hidden('promo', { prefill_source: 'url', url_param: 'promo-code' }))).toBe('promo-code');
    });
});

describe('buildPrefill', () => {
    it('maps a url-sourced field from its declared parameter', () => {
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url', url_param: 'promo-code' })] });

        expect(buildPrefill(schema, '?promo-code=SPRING')).toEqual({ promo: 'SPRING' });
    });

    it('maps a url-sourced field from its key when no parameter name is declared', () => {
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url' })] });

        expect(buildPrefill(schema, '?promo=SPRING')).toEqual({ promo: 'SPRING' });
    });

    it('seeds a fixed-source field from default_value, with no query string at all', () => {
        const schema = schemaResponse({
            fields: [hidden('campaign', { prefill_source: 'fixed' }, { default_value: 'newsletter' })],
        });

        expect(buildPrefill(schema, '')).toEqual({ campaign: 'newsletter' });
    });

    it('ignores a fixed-source field with a blank literal, matching applyFixedPrefills()', () => {
        const blank = schemaResponse({ fields: [hidden('campaign', { prefill_source: 'fixed' }, { default_value: '' })] });
        const missing = schemaResponse({ fields: [hidden('campaign', { prefill_source: 'fixed' })] });

        expect(buildPrefill(blank, '')).toEqual({});
        expect(buildPrefill(missing, '')).toEqual({});
    });

    it('ignores a parameter that matches no declared field', () => {
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url' })] });

        expect(buildPrefill(schema, '?utm_source=newsletter&other=1')).toEqual({});
    });

    it('ignores a parameter matching a NON-hidden field key — this is not a general prefill feature', () => {
        const schema = schemaResponse({ fields: [field({ key: 'full_name' })] });

        expect(buildPrefill(schema, '?full_name=Maria')).toEqual({});
    });

    it('ignores a hidden field that declared no source — opt-in, fail-closed', () => {
        // The whole point of requiring `prefill_source`: a hidden field is not URL-writable by accident.
        const schema = schemaResponse({ fields: [hidden('secret', null)] });

        expect(buildPrefill(schema, '?secret=tampered')).toEqual({});
    });

    it('drops an over-cap parameter rather than carrying an unsubmittable value', () => {
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url' })] });

        expect(buildPrefill(schema, `?promo=${'x'.repeat(MAX_PREFILL_BYTES)}`)).toEqual({
            promo: 'x'.repeat(MAX_PREFILL_BYTES),
        });
        expect(buildPrefill(schema, `?promo=${'x'.repeat(MAX_PREFILL_BYTES + 1)}`)).toEqual({});
    });

    it('measures the cap in BYTES, mirroring PHP strlen()', () => {
        // 1000 two-byte characters = 2000 bytes. `.length` would see 1000 and wave through 1001 as well,
        // which the server would then 422 — the exact SPA/server disagreement this mirror prevents.
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url' })] });
        const atCap = 'é'.repeat(MAX_PREFILL_BYTES / 2);

        expect(buildPrefill(schema, `?promo=${encodeURIComponent(atCap)}`)).toEqual({ promo: atCap });
        expect(buildPrefill(schema, `?promo=${encodeURIComponent(atCap + 'é')}`)).toEqual({});
    });

    it('ignores an empty parameter value', () => {
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url' })] });

        expect(buildPrefill(schema, '?promo=')).toEqual({});
    });

    it('accepts a search string with no leading question mark', () => {
        const schema = schemaResponse({ fields: [hidden('promo', { prefill_source: 'url' })] });

        expect(buildPrefill(schema, 'promo=SPRING')).toEqual({ promo: 'SPRING' });
    });
});

describe('the runtime store', () => {
    const prefillSchema = () =>
        schemaResponse({
            fields: [
                hidden('campaign', { prefill_source: 'fixed' }, { default_value: 'newsletter', sequence: 0 }),
                hidden('promo', { prefill_source: 'url' }, { sequence: 1 }),
                field({ key: 'full_name', sequence: 2, label: 'Your name (offer ${promo})' }),
            ],
        });

    it('seeds both prefill sources into the answer map', () => {
        const runtime = createFormRuntime(prefillSchema(), { search: '?promo=SPRING' });

        expect(runtime.answers.campaign).toBe('newsletter');
        expect(runtime.answers.promo).toBe('SPRING');
    });

    it('lets a restored answer win over a prefill', () => {
        // A resumed draft carries no query string, and the value it saved is the one the respondent's
        // session was built against — so restore must be applied last.
        const runtime = createFormRuntime(prefillSchema(), {
            search: '?promo=SPRING',
            initialAnswers: { promo: 'SAVED', full_name: 'Maria' },
        });

        expect(runtime.answers.promo).toBe('SAVED');
        expect(runtime.answers.full_name).toBe('Maria');
        // A key the restore did not mention keeps its prefill.
        expect(runtime.answers.campaign).toBe('newsletter');
    });

    it('fills a piped label from a url prefill on the very first render', () => {
        // Doc #26 §3.1's stated reason `hidden` is a pipeable source at all: the value exists before the
        // first label renders.
        const runtime = createFormRuntime(prefillSchema(), { search: '?promo=SPRING' });
        const nameField = runtime.renderModel.fields.find((f) => f.key === 'full_name')!;

        expect(runtime.labelFor(nameField)).toBe('Your name (offer SPRING)');
    });

    it('renders a hole as empty when the link carried no parameter', () => {
        const runtime = createFormRuntime(prefillSchema(), {});
        const nameField = runtime.renderModel.fields.find((f) => f.key === 'full_name')!;

        // §3.4 — never the raw `${promo}` token, never `undefined`.
        expect(runtime.labelFor(nameField)).toBe('Your name (offer )');
    });

    it('never puts a hidden, calculated or page_break field in the step model', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                fields: [
                    hidden('campaign', { prefill_source: 'fixed' }, { default_value: 'x', sequence: 0 }),
                    field({ key: 'total', field_type: 'calculated', sequence: 1 }),
                    field({ key: 'brk', field_type: 'page_break', sequence: 2 }),
                    field({ key: 'full_name', sequence: 3 }),
                ],
            }),
            {},
        );

        // Before H7 all three fell through to `controlFor()`'s 'unsupported' branch and rendered the encode
        // channel's "Not available for manual entry yet (Phase 2)" notice on a respondent's form.
        expect(runtime.visibleSteps.value.flatMap((s) => s.fieldKeys)).toEqual(['full_name']);
    });

    it('drops a section entirely when it holds nothing but non-rendering fields', () => {
        const runtime = createFormRuntime(
            schemaResponse({
                sections: [section({ key: 'meta', sequence: 0 }), section({ key: 'about', sequence: 1 })],
                fields: [
                    hidden('campaign', { prefill_source: 'fixed' }, { default_value: 'x', section_key: 'meta', sequence: 0 }),
                    field({ key: 'full_name', section_key: 'about', sequence: 1 }),
                ],
            }),
            {},
        );

        // A heading over an empty panel is worse than no section at all.
        expect(runtime.visibleSteps.value.map((s) => s.key)).toEqual(['about']);
    });

    it('still submits a prefilled hidden answer in effectiveAnswers', () => {
        // Not rendering it must not mean not recording it — the field exists to be recorded.
        const runtime = createFormRuntime(prefillSchema(), { search: '?promo=SPRING' });

        expect(runtime.effectiveAnswers.value).toMatchObject({ campaign: 'newsletter', promo: 'SPRING' });
    });
});
