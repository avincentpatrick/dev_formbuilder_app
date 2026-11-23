import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { MdsCheckbox, MdsNumberInput } from '@meridian/design-system';
import {
    answerChoices,
    answersSentence,
    countedLabel,
    itemCount,
    lineContext,
    pickGroups,
    pickLabel,
    ruleSentence,
    shareLabel,
    statusLabel,
    statusVariant,
} from './agreement-view';
import type { AgreementLine, AgreementPick, AgreementResult, AgreementSpec, Result } from './types';

/**
 * M250 (`agreement-report`, `D234`) — the agreement list on the page.
 *
 * The server decides every number and every status; what is pinned here is that the page says them truly: the share
 * as the server rounded it (down — never up to the threshold it missed), a dash and not "0%" where nobody gave a view,
 * each item's own answers, the list's rule in a sentence, and a real captioned table rather than a picture.
 */

vi.mock('./exploreClient', () => ({ fetchChartResult: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ Link: { name: 'Link', template: '<a><slot /></a>' } }));

const ExploreAgreementTable = (await import('./ExploreAgreementTable.vue')).default;
const ExploreAgreementControls = (await import('./ExploreAgreementControls.vue')).default;
const ExploreResult = (await import('./ExploreResult.vue')).default;

const LIMITS = {
    max_picks: 100,
    max_items: 500,
    max_marks: 50,
    min_threshold: 50,
    max_threshold: 100,
    default_threshold: 75,
    max_min_views: 1000,
    default_min_views: 6,
};

function line(overrides: Partial<AgreementLine> = {}): AgreementLine {
    return {
        key: 'q_view',
        pick: 'answer:q_view',
        label: 'Do you agree?',
        question: 'Do you agree?',
        column: null,
        section: 'Terms',
        position: 0,
        multiple: false,
        responses: 10,
        views: 8,
        left_out: 2,
        answers: [
            { value: 'agree', label: 'Agree', responses: 6, left_out: false, counted: true },
            { value: 'agree_changes', label: 'Agree with changes', responses: 0, left_out: false, counted: false },
            { value: 'disagree', label: 'Disagree', responses: 2, left_out: false, counted: false },
            { value: 'noview', label: 'No view or opinion', responses: 2, left_out: true, counted: false },
        ],
        basis: 'most_common',
        counted: [{ value: 'agree', label: 'Agree' }],
        agreeing: 6,
        share: 75,
        status: 'short_list',
        ...overrides,
    };
}

function list(items: AgreementLine[] = [line()], overrides: Partial<AgreementResult> = {}): AgreementResult {
    const count = (status: string): number => items.filter((l) => l.status === status).length;

    return {
        threshold: 75,
        min_views: 6,
        leave_out: [{ value: 'noview', label: 'No view or opinion' }],
        agreeing: [],
        summary: { items: items.length, short_list: count('short_list'), discuss: count('discuss'), too_few: count('too_few') },
        items,
        ...overrides,
    };
}

function result(agreement: AgreementResult): Result {
    return {
        chart: 'agreement',
        dimensions: [],
        measures: [],
        categories: [],
        series: [],
        totals: { rows: 10, values: [], coverage: [] },
        meta: {
            source: 'agreement',
            source_label: 'Agreement across questions',
            row_noun: 'responses',
            form: { id: 'f1', title: 'Consultation' },
            from: '2026-03-01',
            to: '2026-03-31',
            timezone: 'Asia/Manila',
            range_label: 'Submitted',
            groups: agreement.items.length,
            hidden_groups: 0,
            hidden_series: 0,
            capped: false,
            notes: [],
        },
        agreement,
    };
}

function pick(overrides: Partial<AgreementPick> = {}): AgreementPick {
    return {
        value: 'answer:q_view',
        label: 'Do you agree?',
        question: 'Do you agree?',
        column: null,
        section: 'Terms',
        kind: 'question',
        multiple: false,
        rows: 0,
        options: [
            { value: 'agree', label: 'Agree' },
            { value: 'noview', label: 'No view or opinion' },
        ],
        ...overrides,
    };
}

const TABLE_PICK = pick({
    value: 'grid:tor:agree',
    label: 'Your view',
    question: 'Terms of reference',
    column: 'Your view',
    kind: 'table_column',
    rows: 7,
    options: [
        { value: 'agree', label: 'Agree' },
        { value: 'disagree', label: 'Disagree' },
        { value: 'noview', label: 'No view' },
    ],
});

const CHOICE_PICK = pick({
    value: 'answer:qs01',
    label: 'Which measure?',
    question: 'Which measure?',
    section: 'Big choices',
    options: [
        { value: 'a01', label: 'Measure A01' },
        { value: 'noview', label: 'Em ipsu' },
    ],
});

function spec(overrides: Partial<AgreementSpec> = {}): AgreementSpec {
    return { items: [], leave_out: [], agreeing: [], threshold: 75, min_views: 6, ...overrides };
}

// ── The words ───────────────────────────────────────────────────────────────────────────────────────────

describe('agreement-view — what the page says', () => {
    it('names each status, and colours it from the shared badge variants', () => {
        expect([statusLabel('short_list'), statusLabel('discuss'), statusLabel('too_few')]).toEqual(['On the short list', 'To discuss', 'Too few views']);
        expect([statusVariant('short_list'), statusVariant('discuss'), statusVariant('too_few')]).toEqual(['success', 'warning', 'neutral']);
    });

    it('prints the share as the server rounded it, and a dash where nobody gave a view', () => {
        expect(shareLabel(75)).toBe('75%');
        expect(shareLabel(66.6)).toBe('66.6%');
        expect(shareLabel(0)).toBe('0%');
        expect(shareLabel(null)).toBe('—');
    });

    it('lists the answers given, the left-out ones marked, and none that nobody gave', () => {
        expect(answersSentence(line())).toBe('Agree 6 · Disagree 2 · No view or opinion 2 (left out)');
        expect(countedLabel(line({ counted: [{ value: '4', label: 'Agree' }, { value: '5', label: 'Strongly agree' }] }))).toBe('Agree + Strongly agree');
        expect(countedLabel(line({ counted: [] }))).toBe('—');
    });

    it('places a table row under its question and column, and a question under its section', () => {
        expect(lineContext(line({ label: 'T01: Purpose', question: 'Terms of reference', column: 'Your view' }))).toBe('Terms of reference · Your view');
        expect(lineContext(line({ label: 'Cleanliness', question: 'Satisfaction' }))).toBe('Satisfaction');
        expect(lineContext(line())).toBe('Terms');
    });

    it('states the rule the list was counted by', () => {
        expect(ruleSentence(list())).toBe(
            'On the short list: at least 75% agreeing, out of at least 6 who gave a view. Left out: No view or opinion. Each item’s share is of its most common answer.',
        );
        expect(ruleSentence(list([line()], { leave_out: [], agreeing: [{ value: '4', label: 'Agree' }] }))).toBe(
            'On the short list: at least 75% agreeing, out of at least 6 who gave a view. Counted as agreeing where offered: Agree; elsewhere, each item’s most common answer.',
        );
    });

    it('groups the picks by section in the form’s order, and counts a table column as its rows', () => {
        expect(pickGroups([pick(), TABLE_PICK, CHOICE_PICK, pick({ value: 'answer:x', section: '' })]).map((g) => [g.section, g.picks.length])).toEqual([
            ['Terms', 2],
            ['Big choices', 1],
            ['Questions', 1],
        ]);
        expect(pickLabel(TABLE_PICK)).toBe('Your view · Terms of reference · 7 rows');
        expect(pickLabel(pick({ kind: 'scale_table', question: 'Satisfaction', rows: 1 }))).toBe('Satisfaction · 1 row');
        expect(pickLabel(pick())).toBe('Do you agree?');
        expect(itemCount([pick(), TABLE_PICK, CHOICE_PICK], ['answer:q_view', 'grid:tor:agree'])).toBe(8);
    });

    it('offers each answer of the chosen questions once, with its first wording, and keeps one chosen earlier', () => {
        expect(answerChoices([pick(), TABLE_PICK, CHOICE_PICK], ['answer:q_view', 'grid:tor:agree'])).toEqual([
            { value: 'agree', label: 'Agree' },
            { value: 'noview', label: 'No view or opinion' },
            { value: 'disagree', label: 'Disagree' },
        ]);
        expect(answerChoices([pick()], [], ['noview'])).toEqual([{ value: 'noview', label: 'noview' }]);
    });
});

// ── The table ───────────────────────────────────────────────────────────────────────────────────────────

describe('ExploreAgreementTable', () => {
    const LINES = [
        line(),
        line({ key: 'tor.t03.agree', label: 'T03: How it meets', question: 'Terms of reference', column: 'Your view', share: 33.3, agreeing: 4, views: 12, status: 'discuss' }),
        line({ key: 'qm07b', label: 'Should the pilot wait?', share: null, agreeing: 0, views: 0, left_out: 12, status: 'too_few', counted: [] }),
    ];

    it('is a real captioned table, one row per item in the server’s order, with its status as a badge', () => {
        const wrapper = mount(ExploreAgreementTable, { props: { list: list(LINES), title: 'Meeting short list' } });

        expect(wrapper.findAll('caption')).toHaveLength(1);
        expect(wrapper.get('caption').text()).toBe('Meeting short list: how far respondents agree, item by item');
        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[0].text()).toContain('Do you agree?');
        expect(rows[0].text()).toContain('75%');
        expect(rows[0].get('.mds-badge').text()).toBe('On the short list');
        expect(rows[1].text()).toContain('Terms of reference · Your view');
        expect(rows[1].text()).toContain('33.3%');
        expect(rows[1].get('.mds-badge').text()).toBe('To discuss');
        // Nobody gave a view: a dash, never "0%".
        expect(rows[2].text()).toContain('—');
        expect(rows[2].text()).not.toContain('0%');
        expect(rows[2].get('.mds-badge').text()).toBe('Too few views');
        wrapper.unmount();
    });

    it('counts the short list, the items to discuss and those with too few views, and states the rule', () => {
        const wrapper = mount(ExploreAgreementTable, { props: { list: list(LINES), title: 'Meeting short list' } });

        const tiles = wrapper.findAll('.mds-stat-tile').map((tile) => tile.text());
        expect(tiles).toHaveLength(3);
        expect(tiles[0]).toContain('On the short list');
        expect(tiles[0]).toContain('of 3 items');
        expect(tiles[1]).toContain('To discuss');
        expect(tiles[2]).toContain('Too few views');
        expect(tiles.map((text) => text.trim().charAt(0))).toEqual(['1', '1', '1']);
        expect(wrapper.get('.agreement__rule').text()).toContain('at least 75% agreeing');
        wrapper.unmount();
    });

    it('on a board tile, shows the counts and the first short-listed items, and no table', () => {
        const wrapper = mount(ExploreAgreementTable, { props: { list: list(LINES), title: 'Meeting short list', compact: true } });

        expect(wrapper.find('table').exists()).toBe(false);
        expect(wrapper.findAll('.agreement__short li').map((li) => li.text())).toEqual(['Do you agree? · 75%']);
        wrapper.unmount();
    });

    it('is drawn by ExploreResult in place of a chart, under the range sentence', () => {
        const wrapper = mount(ExploreResult, { props: { result: result(list(LINES)), refusal: null, loading: false, title: 'Meeting short list' } });

        expect(wrapper.find('.agreement').exists()).toBe(true);
        expect(wrapper.find('.explore-chart').exists()).toBe(false);
        expect(wrapper.get('.explore-result__range').text()).toContain('10 responses');
        wrapper.unmount();
    });
});

// ── The controls ────────────────────────────────────────────────────────────────────────────────────────

describe('ExploreAgreementControls', () => {
    function controls(current: AgreementSpec, picks: AgreementPick[] = [pick(), TABLE_PICK, CHOICE_PICK]) {
        return mount(ExploreAgreementControls, { props: { picks, spec: current, limits: LIMITS } });
    }

    function lastSpec(wrapper: ReturnType<typeof controls>): AgreementSpec {
        const emitted = wrapper.emitted('change') ?? [];

        return emitted[emitted.length - 1][0] as AgreementSpec;
    }

    it('keeps ticked questions in the form’s order, however they were ticked', async () => {
        const wrapper = controls(spec({ items: ['answer:qs01'] }));

        await wrapper.findAllComponents(MdsCheckbox)[0].vm.$emit('update:modelValue', true);

        expect(lastSpec(wrapper).items).toEqual(['answer:q_view', 'answer:qs01']);
        wrapper.unmount();
    });

    it('says how many items the chosen questions put on the list', () => {
        const wrapper = controls(spec({ items: ['answer:q_view', 'grid:tor:agree'] }));

        expect(wrapper.text()).toContain('2 chosen · 8 items (at most 500)');
        wrapper.unmount();
    });

    it('chooses and clears a whole section at once', async () => {
        const wrapper = controls(spec());

        await wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Choose every question in Terms')!.trigger('click');
        expect(lastSpec(wrapper).items).toEqual(['answer:q_view', 'grid:tor:agree']);

        await wrapper.setProps({ spec: spec({ items: ['answer:q_view', 'grid:tor:agree', 'answer:qs01'] }) });
        await wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Clear the questions in Terms')!.trigger('click');
        expect(lastSpec(wrapper).items).toEqual(['answer:qs01']);
        wrapper.unmount();
    });

    it('never lets an answer be both left out and counted as agreeing', async () => {
        const wrapper = controls(spec({ items: ['answer:q_view'], agreeing: ['noview'] }));
        const leaveOut = wrapper.findAllComponents(MdsCheckbox).find((c) => c.props('id') === 'agreement-leave-1')!;

        await leaveOut.vm.$emit('update:modelValue', true);

        expect(lastSpec(wrapper)).toMatchObject({ leave_out: ['noview'], agreeing: [] });
        wrapper.unmount();
    });

    it('keeps the threshold and the minimum inside their bounds', async () => {
        const wrapper = controls(spec({ items: ['answer:q_view'] }));
        const [threshold, minimum] = wrapper.findAllComponents(MdsNumberInput);

        await threshold.vm.$emit('update:modelValue', 120);
        expect(lastSpec(wrapper).threshold).toBe(100);
        await minimum.vm.$emit('update:modelValue', 0);
        expect(lastSpec(wrapper).min_views).toBe(1);
        await minimum.vm.$emit('update:modelValue', null);
        expect(lastSpec(wrapper).min_views).toBe(6);
        wrapper.unmount();
    });

    it('says so when the form has no choice question', () => {
        const wrapper = controls(spec(), []);

        expect(wrapper.text()).toContain('This form has no choice question to report on.');
        wrapper.unmount();
    });
});
