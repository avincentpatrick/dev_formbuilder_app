import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import RuntimeSession from '../components/RuntimeSession.vue';
import type { ApiClient } from '../lib/api-client';
import { chapterName, chapterOfStep, chaptersOf, LEADING_CHAPTER_KEY } from '../lib/chapters';
import { resolveShort } from '../lib/schema-mapping';
import type { Bootstrap, RenderSection } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

/**
 * M249 (`progress-chapters`, `D233`) — the progress bar's chapters.
 *
 * A chapter is STARTED by a section and runs until the next one that starts one. Grouping is computed over every
 * section in order and only then filtered to the visible pages, so a hidden starting page still names its chapter.
 * With chapters the bar shows "N of M · {chapter}", one mark per chapter and the current chapter's page dots; with none
 * it draws the step dots exactly as before. A page is named by its progress label, or its title piped as the heading is.
 */

type ChapterSection = Pick<RenderSection, 'key' | 'chapter' | 'chapterTranslations'>;

const s = (key: string, chapter: string | null = null, chapterTranslations: Record<string, string> | null = null): ChapterSection => ({
    key,
    chapter,
    chapterTranslations,
});
const step = (key: string, sectionKey: string | null = key) => ({ key, sectionKey });

describe('chaptersOf()', () => {
    it('is null when no section starts a chapter, so the bar keeps its step dots', () => {
        expect(chaptersOf([s('a'), s('b')], [step('a'), step('b')], 'en')).toBeNull();
    });

    it('groups each page under the chapter the nearest section before it started', () => {
        const chapters = chaptersOf(
            [s('welcome', 'Welcome'), s('about'), s('s1a', 'Your starting point'), s('s1b')],
            [step('welcome'), step('about'), step('s1a'), step('s1b#p2', 's1b'), step('s1b')],
            'en',
        );

        expect(chapters?.map((c) => [c.name, c.steps.map((x) => x.key)])).toEqual([
            ['Welcome', ['welcome', 'about']],
            ['Your starting point', ['s1a', 's1b#p2', 's1b']],
        ]);
    });

    it('lets a hidden starting page still name its later pages, and drops a chapter with no visible page', () => {
        // s1a starts "Your starting point" but is hidden; s2a starts "The problem" and its whole chapter is hidden.
        const chapters = chaptersOf(
            [s('welcome', 'Welcome'), s('s1a', 'Your starting point'), s('s1b'), s('s2a', 'The problem'), s('s3a', 'Equity')],
            [step('welcome'), step('s1b'), step('s3a')],
            'en',
        );

        expect(chapters?.map((c) => [c.key, c.name, c.steps.map((x) => x.key)])).toEqual([
            ['welcome', 'Welcome', ['welcome']],
            ['s1a', 'Your starting point', ['s1b']],
            ['s3a', 'Equity', ['s3a']],
        ]);
    });

    it('puts the lead block and the sections before the first chapter in a leading group with no name', () => {
        const chapters = chaptersOf([s('intro'), s('s1', 'Part one')], [step('__lead__', null), step('intro'), step('s1')], 'en');

        expect(chapters?.[0]).toEqual({ key: LEADING_CHAPTER_KEY, name: null, steps: [step('__lead__', null), step('intro')] });
        expect(chapterName(chapters![0], (x) => (x.key === '__lead__' ? null : `Page ${x.key}`))).toBe('Page intro');
        expect(chapterName(chapters![0], () => null)).toBe('Start');
        expect(chapterName(chapters![1], () => 'unused')).toBe('Part one');
    });

    it('names a chapter in the respondent\'s language when the import gave it one', () => {
        const chapters = chaptersOf([s('a', 'The problem', { fil: 'Ang problema' })], [step('a')], 'fil');

        expect(chapters?.[0].name).toBe('Ang problema');
        expect(chaptersOf([s('a', 'The problem', { fil: 'Ang problema' })], [step('a')], 'es')?.[0].name).toBe('The problem');
    });

    it('finds a page\'s chapter, its place among the chapters and the page\'s place in it', () => {
        const chapters = chaptersOf([s('a', 'One'), s('b'), s('c', 'Two')], [step('a'), step('b'), step('c')], 'en')!;

        expect(chapterOfStep(chapters, 'b')).toMatchObject({ index: 0, page: 1 });
        expect(chapterOfStep(chapters, 'c')).toMatchObject({ index: 1, page: 0 });
        expect(chapterOfStep(chapters, 'gone')).toBeNull();
    });
});

describe('resolveShort() — a short form beside a text that may be translated', () => {
    it('uses its own translation, else itself only while the full text is untranslated there', () => {
        expect(resolveShort('Include', { fil: 'Isama' }, { fil: 'Isama ito' }, 'fil')).toBe('Isama');
        expect(resolveShort('Include', null, { fil: 'Isama ito' }, 'fil')).toBeNull(); // never English beside Filipino
        expect(resolveShort('Include', null, { fil: 'Isama ito' }, 'en')).toBe('Include');
        expect(resolveShort('Include', null, null, 'fil')).toBe('Include'); // the page itself is in its base language
        expect(resolveShort(null, null, null, 'en')).toBeNull();
    });
});

// ── The bar, mounted ─────────────────────────────────────────────────────────────────────────────────────────────

function fakeClient(): ApiClient {
    return {
        fetchSchema: vi.fn(async () => {
            throw new Error('unused in these tests');
        }),
        submit: vi.fn(async () => ({ id: 'id', reference: 'REF', status: 'submitted', created: true })),
        saveDraft: vi.fn(),
        remint: vi.fn(),
        token: () => 't1',
    } as unknown as ApiClient;
}

const bootstrap: Bootstrap = { shareToken: 't1', expiresAt: '', formId: 'f', formTitle: 'T', slug: 's', defaultLocale: 'en', resumeToken: '' };

beforeEach(() => {
    window.localStorage.clear();
});

function chapterSchema() {
    return schemaResponse({
        form: { single_page_mode: false },
        sections: [
            section({ key: 'welcome', label: 'Welcome to the module', sequence: 1, chapter: 'Welcome', progress_label: 'Hello' }),
            section({ key: 'about', label: 'About you, ${name}', sequence: 2 }),
            section({ key: 's1a', label: 'What people mean by equity', sequence: 3, chapter: 'Health equity', progress_label: 'Meaning' }),
        ],
        fields: [
            field({ key: 'name', section_key: 'welcome', label: 'Your name', sequence: 1 }),
            field({ key: 'role', section_key: 'about', label: 'Your role', sequence: 2 }),
            field({ key: 'view', section_key: 's1a', label: 'Your view', sequence: 3 }),
        ],
    });
}

async function next(wrapper: ReturnType<typeof mount>): Promise<void> {
    await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
    await flushPromises();
}

const bar = (wrapper: ReturnType<typeof mount>) => wrapper.find('nav[aria-label="Form progress"]');

describe('ProgressIndicator — with chapters (D233 answer 4)', () => {
    it('shows the chapter and its place, then the page within it under its progress label', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: chapterSchema(), bootstrap, client: fakeClient() } });
        await flushPromises();

        expect(bar(wrapper).find('[data-progress-chapter]').text()).toBe('1 of 2 · Welcome');
        expect(bar(wrapper).find('[data-progress-page]').text()).toBe('Page 1 of 2: Hello');
        expect(bar(wrapper).findAll('ol[aria-label="Chapters"] li')).toHaveLength(2);
        expect(bar(wrapper).find('ol[aria-label="Chapters"] [aria-current="true"]').text()).toBe('Welcome');
        expect(bar(wrapper).find('[aria-current="step"]').text()).toBe('1');
        expect(bar(wrapper).text()).not.toContain('Step 1 of');

        wrapper.unmount();
    });

    it('pipes a title with no progress label, as the heading does (progress-title-unpiped)', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: chapterSchema(), bootstrap, client: fakeClient() } });
        await flushPromises();
        await wrapper.find('input').setValue('Ana');
        await next(wrapper);

        expect(bar(wrapper).find('[data-progress-page]').text()).toBe('Page 2 of 2: About you, Ana');
        expect(bar(wrapper).text()).not.toContain('${name}');

        wrapper.unmount();
    });

    it('offers a visited chapter as a button to its first visited page, and says where the respondent is', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: chapterSchema(), bootstrap, client: fakeClient() } });
        await flushPromises();
        await next(wrapper);
        await next(wrapper);

        expect(bar(wrapper).find('[data-progress-chapter]').text()).toBe('2 of 2 · Health equity');
        expect(bar(wrapper).find('[data-progress-page]').text()).toBe('Page 1 of 1: Meaning');
        expect(wrapper.find('.runtime__sr-live').text()).toContain('Health equity (2 of 2), page 1 of 1: Meaning');

        const back = bar(wrapper).find('ol[aria-label="Chapters"] button');
        expect(back.attributes('aria-label')).toBe('Go to Welcome');
        await back.trigger('click');
        await flushPromises();

        expect(bar(wrapper).find('[data-progress-chapter]').text()).toBe('1 of 2 · Welcome');
        expect(bar(wrapper).find('[data-progress-page]').text()).toBe('Page 1 of 2: Hello');
        // The page dots are this chapter's own: page 2 was visited, so it is a button to it.
        expect(bar(wrapper).find('ol[aria-label="Pages in Welcome"] button').attributes('aria-label')).toBe('Go to page 2: About you, ');

        wrapper.unmount();
    });

    it('keeps the step dots for a form whose sections start no chapter, naming a page by its progress label', async () => {
        const schema = schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 'a', label: 'A long title for the first page', sequence: 1, progress_label: 'First' }),
                section({ key: 'b', label: 'Second', sequence: 2 }),
            ],
            fields: [field({ key: 'one', section_key: 'a', sequence: 1 }), field({ key: 'two', section_key: 'b', sequence: 2 })],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await flushPromises();

        expect(bar(wrapper).find('[data-progress-chapters]').exists()).toBe(false);
        expect(bar(wrapper).find('.progress__label').text()).toBe('Step 1 of 2: First');
        expect(bar(wrapper).findAll('ol li')).toHaveLength(2);

        wrapper.unmount();
    });
});
