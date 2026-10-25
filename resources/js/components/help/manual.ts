/**
 * The in-app user manual (M162, `R-f9211765`, `D114`) — how to create a form, from a blank page to an exported response.
 *
 * ⛔ EVERY ON-SCREEN LABEL IS QUOTED WITH THE FILE THAT DRAWS IT. `ui('Copy link', SHARE)` renders as bold text, and
 * `manual.test.ts` reads `SharePanel.vue` and fails if "Copy link" is no longer in it. A manual that names a button the
 * screen no longer shows is worse than no manual — the reader trusts it and looks for something that is not there — and
 * the builder changed on most days before testing (`M150`–`M161`). So a renamed label turns this file's test red rather
 * than the manual silently wrong: when that happens, move the manual in the same change as the label.
 *
 * A label built at runtime (a count, a form's title) is quoted by its static part only — "Not published", never
 * "Not published — 2 problems to fix first".
 *
 * ⛔ NO MARKUP. Content is data rendered as text nodes by `ManualBlock.vue` and `ManualSpans.vue`; there is no HTML
 * string anywhere in it, because `raw-html-sink-census.test.ts` allows exactly one markup sink in the app and this
 * page does not need one. No screenshots in v1 (`D114`); filed as `R-5cde8465`.
 */

import readiness from './field-readiness.json';

export type ManualSpan =
    | { kind: 'text'; text: string }
    /** An on-screen label, drawn bold, with the repository file that draws it (checked by `manual.test.ts`). */
    | { kind: 'ui'; label: string; from: string }
    /** `#id` jumps to a chapter or section of this page; `/path` opens a page of the app. */
    | { kind: 'link'; text: string; href: string };

export type ManualBlock =
    | { kind: 'paragraph'; spans: ManualSpan[] }
    | { kind: 'steps'; items: ManualSpan[][] }
    | { kind: 'list'; items: ManualSpan[][] }
    | { kind: 'tip'; tone: 'info' | 'success' | 'warning'; spans: ManualSpan[] }
    /**
     * A picture of a screen (M163, `D115`). `src` is a file under `public/` written by
     * `tests/e2e/capture/help-pictures.capture.ts`; `width` and `height` are its CSS size — half its pixels, because it is
     * captured at a device scale of 2 — and `manual.test.ts` checks both against the file.
     */
    | { kind: 'figure'; src: string; alt: string; caption: string; width: number; height: number }
    /**
     * A table (M187, `R-3e6358ed`): one row per item, the row's head as spans (so its labels are checked like any other),
     * and its cells as plain text keyed by column. Drawn by `MdsDataTable`, which turns each row into a card on a phone.
     */
    | { kind: 'table'; caption: string; columns: { key: string; heading: string }[]; rows: { key: string; head: ManualSpan[]; cells: Record<string, string> }[] };

/** The page's two parts: the walk-through (`M162`) and the reference to every control (`M163`). */
export type ManualPart = 'guide' | 'reference';

export const MANUAL_PARTS: { key: ManualPart; title: string }[] = [
    { key: 'guide', title: 'Step by step' },
    { key: 'reference', title: 'Builder reference' },
];

export interface ManualSection {
    id: string;
    title: string;
    blocks: ManualBlock[];
}

export interface ManualChapter {
    id: string;
    part: ManualPart;
    title: string;
    summary: string;
    sections: ManualSection[];
}

/** A chapter as written below; its part comes from the list it is written in. */
type ChapterDraft = Omit<ManualChapter, 'part'>;

type Part = string | ManualSpan;

function spans(parts: Part[]): ManualSpan[] {
    return parts.map((part) => (typeof part === 'string' ? { kind: 'text', text: part } : part));
}

export function ui(label: string, from: string): ManualSpan {
    return { kind: 'ui', label, from };
}

export function link(text: string, href: string): ManualSpan {
    return { kind: 'link', text, href };
}

function p(...parts: Part[]): ManualBlock {
    return { kind: 'paragraph', spans: spans(parts) };
}

function steps(...items: Part[][]): ManualBlock {
    return { kind: 'steps', items: items.map(spans) };
}

function list(...items: Part[][]): ManualBlock {
    return { kind: 'list', items: items.map(spans) };
}

function tip(tone: 'info' | 'success' | 'warning', ...parts: Part[]): ManualBlock {
    return { kind: 'tip', tone, spans: spans(parts) };
}

function figure(src: string, alt: string, caption: string, width: number, height: number): ManualBlock {
    return { kind: 'figure', src, alt, caption, width, height };
}

/**
 * M187 (`R-3e6358ed`): what each question type can do on each surface, from `field-readiness.json` — written by
 * `php artisan help:readiness` from the rules themselves and held equal to them by `FieldReadinessTableTest`, so this
 * page never states a capability the code does not have. Each type is named as the palette names it, with its Adv mark.
 */
function readinessTable(): ManualBlock {
    return {
        kind: 'table',
        caption: 'What each question type can do',
        columns: [{ key: 'type', heading: 'Question type' }, ...readiness.columns],
        rows: readiness.rows.map((row) => ({
            key: row.type,
            head: spans([ui(row.label, FIELD_TYPES), ...(row.advanced ? [' ', ui('Adv', PALETTE)] : []), ...(row.retired ? [' (retired)'] : [])]),
            cells: row.cells,
        })),
    };
}

// The files that draw the labels below. Paths are repository-relative; `manual.test.ts` reads each one.
const NAV = 'resources/js/components/shell/nav-model.ts';
const ROLES = 'app/Support/Authorization/AssignableRoles.php';
const FORMS_LIST = 'resources/js/Pages/forms/Index.vue';
const CREATE = 'resources/js/components/forms/CreateFormModal.vue';
const TEMPLATES = 'resources/js/Pages/forms/Templates.vue';
const FACETS = 'app/Support/Forms/FormListFacets.php';
const ROW_ACTIONS = 'resources/js/components/forms/FormRowActions.vue';
const HUB = 'resources/js/Pages/forms/Show.vue';
const TABS = 'app/Support/Forms/FormTabSet.php';
const BUILDER = 'resources/js/Pages/forms/Builder.vue';
const PALETTE = 'resources/js/components/builder/FieldPalette.vue';
const SAVE_LABEL = 'resources/js/components/builder/save-label.ts';
const CANVAS = 'resources/js/components/builder/BuilderCanvas.vue';
const CONFIG = 'resources/js/components/builder/ConfigPanel.vue';
const CALCULATION_EDITOR = 'resources/js/components/builder/CalculationEditor.vue';
const TYPE_CONTROL = 'resources/js/components/builder/FieldTypeControl.vue';
const FIELD_TYPES = 'app/Enums/FieldType.php';
const CATEGORIES = 'app/Enums/FieldCategory.php';
const PRESENTER = 'app/Services/Forms/BuilderPresenter.php';
const CHOICES = 'resources/js/components/builder/ChoicesEditor.vue';
const VALIDATION = 'resources/js/components/builder/ValidationEditor.vue';
const CONDITIONS = 'resources/js/components/builder/ConditionRows.vue';
const PREVIEW = 'resources/js/components/builder/PreviewPane.vue';
// M185: a grid edited where the Preview draws it, and a repeatable section's layout.
const MATRIX_GRID = 'resources/js/components/submissions/MatrixGrid.vue';
const REPEAT_LAYOUT = 'resources/js/components/builder/RepeatLayoutSelect.vue';
const LOGIC = 'resources/js/components/builder/LogicRail.vue';
const SETTINGS = 'resources/js/components/forms/FormSettingsSections.vue';
const DETAILS = 'resources/js/components/builder/GeneralPanel.vue';
const PAGES = 'resources/js/components/builder/PageModePanel.vue';
const THEME = 'resources/js/components/builder/ThemePanel.vue';
const SCHEDULE = 'resources/js/components/builder/SchedulePanel.vue';
const THANKS = 'resources/js/components/builder/ConfirmationPanel.vue';
const SAVE_RESUME = 'resources/js/components/builder/SaveResumePanel.vue';
const SHARE = 'resources/js/components/forms/SharePanel.vue';
const PUBLISH = 'app/Http/Controllers/Tenant/FormPublishController.php';
const INBOX = 'resources/js/Pages/submissions/Inbox.vue';
const ANALYTICS = 'resources/js/Pages/forms/Analytics.vue';
// M176 (`R-61d43278`): "No negative numbers" is a kind the builder names itself, stored as a minimum of 0.
const RULE_WORDS = 'resources/js/components/builder/field-conversion.ts';

/** Part one, "Step by step" (`M162`): creating a form, from a blank page to an exported response. */
const guide: ChapterDraft[] = [
    {
        id: 'who-can',
        title: 'Before you start: who can do what',
        summary: 'Your role and your workspace’s plan decide which buttons you see.',
        sections: [
            {
                id: 'roles',
                title: 'Your role',
                blocks: [
                    p('Each member of a workspace has a role. Ask the person who invited you if you are not sure which one you have.'),
                    list(
                        ['The Owner and an ', ui('Admin', ROLES), ' can create and edit every form, and manage the workspace.'],
                        ['A ', ui('Form Editor', ROLES), ' can create forms, and edit the forms they created or were given.'],
                        ['A ', ui('Reviewer', ROLES), ' reads and reviews responses, but cannot create forms.'],
                        ['A ', ui('Viewer', ROLES), ' can read responses only.'],
                    ),
                    tip('info', 'If ', ui('Forms', NAV), ' is not in the menu on the left, your role cannot create forms. You can still read this manual.'),
                ],
            },
            {
                id: 'plans',
                title: 'Your workspace’s plan',
                blocks: [
                    p(
                        'Some features come with bigger plans. A feature your workspace’s plan does not include is hidden, not greyed out. So if a button this manual names is missing from your screen, the plan is the likely reason. For example, ',
                        ui('New from template', FORMS_LIST),
                        ' appears only on plans that include templates.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'start',
        title: 'Start a new form',
        summary: 'Begin from a blank form, or from a ready-made template.',
        sections: [
            {
                id: 'blank',
                title: 'Start from a blank form',
                blocks: [
                    steps(
                        ['Click ', ui('Forms', NAV), ' in the menu on the left.'],
                        ['Click ', ui('New form', FORMS_LIST), ' at the top right.'],
                        ['Type a ', ui('Title', CREATE), ' — for example “Household survey”. A ', ui('Description', CREATE), ' is optional.'],
                        ['Click ', ui('Create form', CREATE), '. The builder opens with your new, empty form.'],
                    ),
                    tip('info', 'You can change the title and description at any time, in ', link('Form settings', '#settings'), '.'),
                ],
            },
            {
                id: 'template',
                title: 'Start from a template',
                blocks: [
                    p('A template is a ready-made form — a survey, a registration, a feedback questionnaire — that you then change as you like.'),
                    steps(
                        ['On ', ui('Forms', NAV), ', click ', ui('New from template', FORMS_LIST), '.'],
                        ['Read the cards and pick the closest match.'],
                        ['Click ', ui('Use this template', TEMPLATES), '. A copy opens in the builder; the template itself never changes.'],
                    ),
                ],
            },
            {
                id: 'find',
                title: 'Find a form again',
                blocks: [
                    p('Every form you can work on is listed on ', ui('Forms', NAV), '. To narrow the list:'),
                    list(
                        ['Type in ', ui('Search forms', FORMS_LIST), '.'],
                        [
                            'Click a filter: ',
                            ui('All', FACETS), ', ', ui('Live', FACETS), ', ', ui('Draft', FACETS), ', ',
                            ui('Closing soon', FACETS), ' or ', ui('Archived', FACETS), '.',
                        ],
                        ['Group forms into folders with ', ui('Manage folders', FORMS_LIST), ' and ', ui('Move to folder', ROW_ACTIONS), '.'],
                    ),
                    p(
                        'Click a form’s title to open its own page. To change its questions, click ',
                        ui('Open builder', ROW_ACTIONS), ' in the list, or ', ui('Edit form', HUB), ' on the form’s page.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'builder',
        title: 'Find your way around the builder',
        summary: 'Three columns: what you can add, your form, and the settings of what you selected.',
        sections: [
            {
                id: 'layout',
                title: 'The three columns',
                blocks: [
                    list(
                        ['Left — ', ui('Add a field', PALETTE), ': every kind of question you can add, in groups.'],
                        [
                            'Middle — your form. Switch between ', ui('Structure', BUILDER), ' (the list of sections and questions), ',
                            ui('Preview', BUILDER), ' (the form as a respondent sees it) and ', ui('Logic', BUILDER),
                            ' (a map of which questions show when).',
                        ],
                        ['Right — the settings of the question or section you selected. With nothing selected it says “', ui('Select a field or section to configure it.', CONFIG), '”'],
                    ),
                    p(
                        'On a phone or a narrow window only one column fits. Use the switch at the top — ',
                        ui('Add', BUILDER), ', ', ui('Form', BUILDER), ' and ', ui('Settings', BUILDER), ' — to move between them.',
                    ),
                ],
            },
            {
                id: 'saving',
                title: 'Saving happens by itself',
                blocks: [
                    p('There is no Save button in the builder. Every change is saved as you make it, and the top bar tells you where it stands:'),
                    list(
                        [ui('Saving…', SAVE_LABEL), ' — a change is on its way.'],
                        [ui('All changes saved', SAVE_LABEL), ' — everything you did is stored.'],
                        [ui('Not saved', SAVE_LABEL), ' — the last change did not reach the server. Check your connection, then make the change again.'],
                    ),
                    p('Made a mistake? ', ui('Undo', BUILDER), ' takes back your last change, and ', ui('Redo', BUILDER), ' puts it back.'),
                    tip('warning', 'Saved is not the same as live. Respondents see your changes only after you ', link('publish', '#publish'), '.'),
                ],
            },
        ],
    },
    {
        id: 'questions',
        title: 'Add questions and sections',
        summary: 'Click a question type to add it, group questions into sections, and drag to reorder.',
        sections: [
            {
                id: 'add-question',
                title: 'Add a question',
                blocks: [
                    steps(
                        ['In ', ui('Structure', BUILDER), ', click the section the question belongs in, or any question already in it. On a form with no sections, skip this step.'],
                        ['In ', ui('Add a field', PALETTE), ' on the left, click the kind of question you want. It is added to that section.'],
                        ['The new question is selected, so its settings open on the right. Type its ', ui('Label', CONFIG), ' — the question respondents read.'],
                    ),
                    tip(
                        'info',
                        'An empty section shows ', ui('Add a question here', CANVAS),
                        ' — click it, choose the kind of question, and it is added straight into that section. In the preview, each page’s “Add a question to …” does the same at the end of that page.',
                    ),
                ],
            },
            {
                id: 'types',
                title: 'Which kind of question to choose',
                blocks: [
                    p('The kinds of question are grouped under ', ui('Text', CATEGORIES), ', ', ui('Number', CATEGORIES), ', ',
                        ui('Date & time', CATEGORIES), ', ', ui('Choice', CATEGORIES), ', ', ui('Rating', CATEGORIES), ', ',
                        ui('Location', CATEGORIES), ', ', ui('Media', CATEGORIES), ' and ', ui('Layout', CATEGORIES), '. The ones most forms need:'),
                    list(
                        [ui('Text', CATEGORIES), ' — a name, an address, a comment. Choose ', ui('Short text', FIELD_TYPES), ' or ', ui('Long text', FIELD_TYPES), ' in its settings.'],
                        [ui('Number', CATEGORIES), ' — an age, a count, an amount. Choose ', ui('Whole number', FIELD_TYPES), ' or ', ui('Decimal number', FIELD_TYPES), ' in its settings.'],
                        [ui('Single choice', FIELD_TYPES), ' — pick exactly one answer from a short list.'],
                        [ui('Multiple choice', FIELD_TYPES), ' — tick every answer that applies.'],
                        [ui('Dropdown', FIELD_TYPES), ' — pick one answer from a long list.'],
                        ['A yes-or-no question is a ', ui('Single choice', FIELD_TYPES), ': on its ', ui('Options', CONFIG), ' tab, press ', ui('Add Yes and No', CHOICES), '.'],
                        [ui('Date', FIELD_TYPES), ', ', ui('Time', FIELD_TYPES), ' and ', ui('Date & time', FIELD_TYPES), ' — a calendar or clock picker.'],
                        [ui('Email', FIELD_TYPES), ' and ', ui('Phone', FIELD_TYPES), ' — checked to look like an email address or a phone number.'],
                        [ui('Photo', FIELD_TYPES), ', ', ui('File upload', FIELD_TYPES), ' and ', ui('Signature', FIELD_TYPES), ' — the respondent attaches or draws something.'],
                        [ui('Note / label', FIELD_TYPES), ' — text, a heading or an image for the respondent to read. It asks nothing.'],
                        [ui('Page break', FIELD_TYPES), ' — starts a new page when the form is shown one step at a time.'],
                    ),
                    p('A type marked “Adv” is an advanced one: it works like any other, and its settings are more technical. ', link('What each type can do', '#types-readiness'), ' shows where every type works.'),
                ],
            },
            {
                id: 'sections',
                title: 'Group questions into sections',
                blocks: [
                    p('A section is a group of related questions under one heading — for example “Household members” or “Health history”.'),
                    steps(
                        ['Click ', ui('Add section', CANVAS), ' below your questions (on an empty form, ', ui('Add a section', CANVAS), ').'],
                        ['With the section selected, type its title on the right.'],
                        ['Add questions into it, or drag existing questions in.'],
                    ),
                ],
            },
            {
                id: 'arrange',
                title: 'Reorder, copy, rename and delete',
                blocks: [
                    list(
                        ['Reorder: drag a question or a section by its handle (the six dots on its left). Without a mouse, focus the handle, press Enter or Space, move with the arrow keys, then press Enter again.'],
                        ['Move several at once: tick the box beside each question in ', ui('Structure', BUILDER), ' (hold Shift to tick every question between two), choose a section under ', ui('Move to', CANVAS), ', and press ', ui('Move', CANVAS), '. They keep their order, and one ', ui('Undo', BUILDER), ' puts them all back.'],
                        ['Rename: double-click a question’s label in ', ui('Structure', BUILDER), ' and type. Press Enter to keep it.'],
                        ['Copy: ', ui('Duplicate field', CANVAS), ' adds a copy right below the question.'],
                        ['Delete: ', ui('Delete field', CANVAS), ' for a question, ', ui('Delete section', CANVAS), ' for a section. ', ui('Undo', BUILDER), ' brings it back. If responses already hold answers to the question, you are told how many first: they keep them, marked as removed from the form.'],
                    ),
                ],
            },
        ],
    },
    {
        id: 'question-settings',
        title: 'Set up a question',
        summary: 'Wording, required or optional, the choices, and the rules an answer must pass.',
        sections: [
            {
                id: 'basics',
                title: 'The basics',
                blocks: [
                    p('Click a question to select it. Its settings open on the right, in tabs. The first tab is ', ui('Basics', CONFIG), ':'),
                    list(
                        [ui('Label', CONFIG), ' — the question itself, as respondents read it.'],
                        [ui('Help text', CONFIG), ' — a short hint shown under the question.'],
                        [ui('Placeholder', CONFIG), ' — sample text shown in an empty box, such as “09XX XXX XXXX”.'],
                        [
                            ui('Requiredness', CONFIG), ' — ', ui('Optional', PRESENTER), ' (may be left blank), ', ui('Required', PRESENTER),
                            ' (must be answered) or ', ui('Conditional', PRESENTER), ' (required only when something else is true — set it under ',
                            ui('Required when…', CONFIG), ').',
                        ],
                    ),
                    p(
                        'Picked the wrong kind of question? The ', ui('Question type', TYPE_CONTROL), ' row at the top of ',
                        ui('Basics', CONFIG), ' has a ', ui('Change type', TYPE_CONTROL), ' button. To refuse answers below zero on a number, add the rule ',
                        ui('No negative numbers', RULE_WORDS), ' on the ', ui('Validation', CONFIG), ' tab.',
                    ),
                ],
            },
            {
                id: 'choices',
                title: 'The choices of a choice question',
                blocks: [
                    p('A ', ui('Single choice', FIELD_TYPES), ', ', ui('Multiple choice', FIELD_TYPES), ' or ', ui('Dropdown', FIELD_TYPES), ' question has an ', ui('Options', CONFIG), ' tab:'),
                    steps(
                        ['Click ', ui('Add option', CHOICES), ' once for each choice.'],
                        ['Fill in its ', ui('Label', CHOICES), ' — the words respondents see — and its ', ui('Value', CHOICES), ' — the short code saved in the results and the export, such as “m” for “Male”.'],
                        ['Pick a ', ui('Choice layout', CONFIG), ' if you want short choices side by side instead of one per line.'],
                    ),
                ],
            },
            {
                id: 'rules',
                title: 'Rules an answer must pass',
                blocks: [
                    p('The ', ui('Validation', CONFIG), ' tab stops a wrong answer before it is sent — for example an age over 120, or a code shorter than 6 characters.'),
                    steps(
                        ['Click ', ui('Add rule', VALIDATION), '.'],
                        ['Choose the kind of rule and the value it checks against.'],
                        ['Write the message respondents see when the rule fails, in “', ui('Error message shown to the respondent', VALIDATION), '”.'],
                    ),
                ],
            },
            {
                id: 'advanced',
                title: 'Advanced settings',
                blocks: [
                    p(
                        'The ', ui('Advanced', CONFIG), ' tab holds the ', ui('Field key', CONFIG),
                        ' (the question’s short name in exports and formulas), a ', ui('Default value', CONFIG),
                        ', and when the question is shown — see ', link('Show or skip questions', '#logic'), '. Most forms can leave this tab alone.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'logic',
        title: 'Show or skip questions',
        summary: 'Ask a question only when an earlier answer makes it relevant.',
        sections: [
            {
                id: 'show-when',
                title: 'Show a question only when…',
                blocks: [
                    p('Example: ask “How many months pregnant?” only when the answer to “Are you pregnant?” is Yes.'),
                    steps(
                        ['Select the question that should sometimes be hidden — “How many months pregnant?”.'],
                        ['Open its ', ui('Advanced', CONFIG), ' tab and find “', ui('Show this question only when…', CONFIG), '”.'],
                        ['Click ', ui('Add condition', CONDITIONS), ', then choose the earlier question, the comparison and the answer — “Are you pregnant?” is Yes.'],
                        ['For more than one condition, choose ', ui('All of', CONDITIONS), ' (every condition must hold) or ', ui('Any of', CONDITIONS), ' (one is enough).'],
                    ),
                    p(
                        'Above the condition, ', ui('Show to respondents', CONFIG), ' reads ', ui('Only when…', CONFIG), ' once a condition is set. Choose ', ui('Never', CONFIG),
                        ' to keep a question on the form without asking anyone — its condition is kept for later, and it is never required — or ', ui('Always', CONFIG), ' to remove the condition.',
                    ),
                    p('A whole section can be shown or skipped the same way: select the section and use “', ui('Show this section only when…', CONFIG), '”.'),
                    tip('info', 'Check your conditions on the ', ui('Logic', BUILDER), ' view, which writes out every condition in plain words, and try them in', link('Preview', '#preview'), '.'),
                ],
            },
        ],
    },
    {
        id: 'preview',
        title: 'Try your form in Preview',
        summary: 'Fill in your own form exactly as a respondent will, before anyone else sees it.',
        sections: [
            {
                id: 'use-preview',
                title: 'Use Preview',
                blocks: [
                    p(
                        'Click ', ui('Preview', BUILDER),
                        ' in the middle column. Answer the questions as a respondent would: required questions, rules and show-or-skip conditions all work here, and nothing you type is saved as a response.',
                    ),
                    p('You can still edit while you preview: double-click a question’s wording to change it, or drag a question by its handle to move it.'),
                    p('A section whose every question is hidden — a calculation, a hidden field, or a question set to Never — still shows here as a card that says respondents won’t see it. Click a question on the card to open its settings.'),
                    p('Choices from a list file or from another form’s answers show here too, and so do a Short text’s suggestions. A long list shows its first 200 choices, and the question says how many there are.'),
                    p(
                        'A grid can be built right here: ', ui('Add row', MATRIX_GRID), ' and Add column sit under it, and the pencil beside a row’s or a column’s name renames it. Each change is saved at once and can be undone. A grid whose rows come from a list file or another form says so, and its rows are changed on the ',
                        ui('Grid', CONFIG), ' tab.',
                    ),
                    tip(
                        'info',
                        'The box “', ui('What this preview does not do', PREVIEW),
                        '” lists what only the live form can do — photos, files and locations, for example. Test those on the live form after you publish.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'settings',
        title: 'Form settings',
        summary: 'The title, one page or step by step, the colours, when it is open, and the thank-you screen.',
        sections: [
            {
                id: 'open-settings',
                title: 'Open the settings',
                blocks: [
                    p('In the builder, click ', ui('Form settings', BUILDER), ' in the top bar. On the form’s own page, click the ', ui('Settings', TABS), ' tab. Both show the same sections:'),
                    list(
                        [ui('Details', SETTINGS), ' — the title and description. Click ', ui('Save details', DETAILS), ' after a change.'],
                        [ui('Pages', SETTINGS), ' — ', ui('Step by step', PAGES), ' (one section per screen) or ', ui('One page', PAGES), ' (everything on one long page). Saves as soon as you choose.'],
                        [ui('Theme', SETTINGS), ' — the colours of the form. ', ui('Workspace brand', THEME), ' uses your workspace’s colours. Saves as soon as you choose.'],
                        [ui('Share', SETTINGS), ' — the public link. See ', link('Share your form', '#share'), '.'],
                        [
                            ui('Schedule', SETTINGS), ' — when the form opens and closes (', ui('Opens at', SCHEDULE), ', ', ui('Closes at', SCHEDULE),
                            ') and an optional ', ui('Max responses', SCHEDULE), '. Click ', ui('Save schedule', SCHEDULE), '. Its ', ui('Timezone', SCHEDULE),
                            ' is also the day the form’s formulas call today: a new form starts on Asia/Manila.',
                        ],
                        [ui('Thank-you message', SETTINGS), ' — what respondents see after they submit, and where they go next. Click ', ui('Save thank-you screen', THANKS), '.'],
                        [ui('Save and finish later', SETTINGS), ' — tick ', ui('Allow respondents to save and finish later', SAVE_RESUME), ' to let respondents come back to a half-finished form.'],
                    ),
                    p('Some sections appear only on some plans, or only to some roles.'),
                ],
            },
        ],
    },
    {
        id: 'publish',
        title: 'Publish your form',
        summary: 'Publishing makes your current questions the version respondents fill in.',
        sections: [
            {
                id: 'how-to-publish',
                title: 'Publish',
                blocks: [
                    steps(
                        ['Click ', ui('Publish', BUILDER), ' in the builder’s top bar — or ', ui('Publish draft', HUB), ' on the form’s own page.'],
                        ['When it works you see “', ui('Published version', PUBLISH), '” with its number.'],
                    ),
                    p(
                        'If a red box says “', ui('Not published', BUILDER),
                        '”, something has to be fixed first — a choice question with no choices, for example. It lists each problem. Fix them, then click ',
                        ui('Publish', BUILDER), ' again.',
                    ),
                    tip(
                        'warning',
                        'Changes you make after publishing are saved, but respondents do not see them until you click ',
                        ui('Publish', BUILDER), ' again. Each publish makes a new version.',
                    ),
                    tip('info', 'Publishing alone does not open the public link. Turn it on in ', link('Share your form', '#share'), '.'),
                ],
            },
            {
                id: 'versions',
                title: 'Versions',
                blocks: [
                    p('Every publish is kept. On the form’s own page, the ', ui('Versions', HUB), ' panel lists them (in the forms list it is ', ui('Version history', ROW_ACTIONS), '):'),
                    list(
                        [ui('What changed', HUB), ' — the questions that version added, removed or changed, with the note written when it was published.'],
                        [ui('View', HUB), ' — open that version read-only, exactly as it asked its questions. You can try them; nothing is saved.'],
                        [ui('Print blank', HUB), ' — a paper copy of that version to print and fill in by hand.'],
                        [ui('Restore', HUB), ' — copy an older version back into your draft (confirm with ', ui('Restore into draft', HUB), '). It changes nothing live until you publish again.'],
                    ),
                    p(
                        'To start a new form from this one, click ', ui('Save as template', BUILDER),
                        ' in the builder; then start a form from that template.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'share',
        title: 'Share your form and collect responses',
        summary: 'Turn on the public link, send it, or enter responses yourself.',
        sections: [
            {
                id: 'public-link',
                title: 'Turn on the public link',
                blocks: [
                    tip('warning', 'The public link is off for every new form. Until you turn it on, anyone who opens it sees “not found”.'),
                    steps(
                        ['Publish the form first (', link('Publish your form', '#publish'), ').'],
                        ['On the form’s own page, click ', ui('Share', HUB), ' — or open ', ui('Form settings', BUILDER), ' and its ', ui('Share', SETTINGS), ' section.'],
                        ['Tick ', ui('Accept responses from the public link', SHARE), '.'],
                        ['Optionally, change the ', ui('Link name', SHARE), ' — the end of the web address.'],
                        ['Click ', ui('Save sharing settings', SHARE), '.'],
                    ),
                    p('Once the link is live you can:'),
                    list(
                        [ui('Copy link', SHARE), ' — paste it into a message, an email or a chat.'],
                        [ui('QR code', SHARE), ' — print it or show it on a screen for phones to scan.'],
                        [ui('Embed on your site', SHARE), ' — put the form inside a web page.'],
                    ),
                ],
            },
            {
                id: 'staff-entry',
                title: 'Enter a response yourself',
                blocks: [
                    p(
                        'To type in an answer someone gave you — on paper or by phone — open the form’s page and click ',
                        ui('New response', HUB), '. This works without the public link.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'responses',
        title: 'See and export responses',
        summary: 'Read each response, see the totals, and download everything as a spreadsheet.',
        sections: [
            {
                id: 'read',
                title: 'Read responses',
                blocks: [
                    p(
                        'Open the form’s own page. Its tabs are ', ui('Overview', TABS), ' (the summary), ', ui('Responses', TABS),
                        ' (every response) and ', ui('Analytics', TABS), ' (', ui('Response statistics', ANALYTICS), ' — totals and charts).',
                    ),
                    p('On ', ui('Responses', TABS), ', click ', ui('View submission', INBOX), ' to read one response in full. ', ui('Submissions', NAV), ' in the left menu lists the responses of every form together.'),
                ],
            },
            {
                id: 'export',
                title: 'Download the responses',
                blocks: [
                    p('On the ', ui('Responses', TABS), ' tab, click ', ui('Export CSV', INBOX), ' or ', ui('Export XLSX', INBOX), ' (for Excel). Each response is one row and each question one column.'),
                    tip('info', 'Reviewing and approving responses, scanning paper forms, inviting members and automatic emails are covered by your workspace’s own guidance, not by this manual.'),
                ],
            },
        ],
    },
    {
        id: 'problems',
        title: 'Problems and questions',
        summary: 'Quick answers to the things that most often go wrong.',
        sections: [
            {
                id: 'faq',
                title: 'Common problems',
                blocks: [
                    list(
                        ['I cannot see ', ui('Forms', NAV), ' in the menu. — Your role cannot create forms. See ', link('Your role', '#roles'), '.'],
                        ['A button in this manual is missing. — Your workspace’s plan may not include it. See ', link('Your workspace’s plan', '#plans'), '.'],
                        ['The top bar says ', ui('Not saved', SAVE_LABEL), '. — Your last change did not reach the server. Check your connection and make the change again.'],
                        ['A red box says ', ui('Not published', BUILDER), '. — Fix each problem it lists, then publish again.'],
                        ['People say my link shows “not found”. — Publish the form, then tick ', ui('Accept responses from the public link', SHARE), ' and click ', ui('Save sharing settings', SHARE), '.'],
                        ['People say my form is closed or full. — Check the dates and ', ui('Max responses', SCHEDULE), ' in ', ui('Schedule', SETTINGS), '.'],
                        ['My change does not show on the live form. — Click ', ui('Publish', BUILDER), ' again. Saved changes stay in the draft until you publish.'],
                        ['I cannot find a form. — Use ', ui('Search forms', FORMS_LIST), ', or the ', ui('All', FACETS), ' and ', ui('Archived', FACETS), ' filters.'],
                        ['Can I delete a form? — You can archive it with ', ui('Archive form', ROW_ACTIONS), '. Its public link closes, its unpublished draft is discarded, and this cannot be undone — but its responses can still be read and exported.'],
                    ),
                ],
            },
        ],
    },
];

// The files that draw the reference's labels, beyond those the guide already names.
const LIBRARY = 'resources/js/components/builder/LibraryPicker.vue';
const CONVERT = 'resources/js/components/builder/ConvertFieldDialog.vue';
const RULE_TYPES = 'app/Enums/ValidationRuleType.php';
const CONDITION_ROW = 'resources/js/components/builder/ConditionRow.vue';
const CONDITION_EDITOR = 'resources/js/components/builder/ConditionEditor.vue';
const APPEARANCE = 'app/Enums/FieldAppearance.php';
const INDEXED = 'app/Enums/IndexedDataType.php';
const LINKED = 'resources/js/components/builder/LinkedChoicesEditor.vue';
const SUGGESTIONS = 'resources/js/components/builder/SuggestionsEditor.vue';
const OTHER_SPECIFY = 'resources/js/components/builder/OtherSpecifyControl.vue';
const CASCADING = 'resources/js/components/builder/CascadingEditor.vue';
// M186 (`R-ed7f3127`): Show only, and where a source form ticks a cascade to share.
const CHOICE_FILTER = 'resources/js/components/builder/ChoiceFilterEditor.vue';
const DATA_SHARING = 'resources/js/components/forms/DataSharingPanel.vue';
const LIKERT_MATRIX = 'resources/js/components/builder/LikertMatrixEditor.vue';
const MATRIX = 'resources/js/components/builder/MatrixEditor.vue';
const GRID_LAYOUT = 'resources/js/components/builder/GridLayoutSelect.vue';
const GEO = 'resources/js/components/builder/GeoEditor.vue';
const MEDIA = 'resources/js/components/builder/MediaEditor.vue';
const SIGNATURE_PAD = 'resources/js/components/submissions/SignaturePad.vue';
const PREFILL = 'resources/js/components/builder/PrefillEditor.vue';
const CONTENT = 'resources/js/components/builder/ContentBlocksEditor.vue';
const FIELD_INPUT = 'resources/js/components/submissions/FieldInput.vue';
const DEFAULT_RULES = 'app/Support/Forms/DefaultFieldRules.php';

// The two pictures' CSS sizes — half their pixels (captured at a device scale of 2). `manual.test.ts` checks them
// against the files, so re-running the capture script and changing a picture's size reddens it until these follow.
const LEFT_PICTURE = { width: 290, height: 813 };
const RIGHT_PICTURE = { width: 370, height: 498 };

/** "Not available for manual entry yet (Phase 2)." — what a respondent sees for a type the form cannot ask yet. */
const NOT_YET = ui('Not available for manual entry yet (Phase 2).', FIELD_INPUT);

/**
 * Part two, "Builder reference" (`M163`, `R-ccc0be00`, `D115`): the left panel, the right panel, and every question type
 * with its settings — the user's *"each input type and its settings/configurations. the left and right panels"*.
 */
const reference: ChapterDraft[] = [
    {
        id: 'left-panel',
        title: 'The left panel: adding questions',
        summary: 'Every kind of question you can add, and your saved questions.',
        sections: [
            {
                id: 'left-panel-tour',
                title: 'What is in the left panel',
                blocks: [
                    figure(
                        '/images/help/left-panel.png',
                        'The builder’s left panel: the Fields and Library switch at the top, the heading “Add a field”, then the question types in groups such as Text, Number and Date & time. Numbered badges mark the parts described below.',
                        'The left panel of the builder, with numbered parts. Below Choice, the list goes on with Rating, Location, Media and Layout.',
                        LEFT_PICTURE.width,
                        LEFT_PICTURE.height,
                    ),
                    steps(
                        [ui('Fields', BUILDER), ' and ', ui('Library', BUILDER), ' — switch between the list of question types and your saved questions. Shown only on plans that include the question library.'],
                        [ui('Add a field', PALETTE), ' — the heading of the list of question types.'],
                        ['A group heading. Questions of the same kind sit together: ', ui('Text', CATEGORIES), ', ', ui('Number', CATEGORIES), ', ', ui('Date & time', CATEGORIES), ', ', ui('Choice', CATEGORIES), ', ', ui('Rating', CATEGORIES), ', ', ui('Location', CATEGORIES), ', ', ui('Media', CATEGORIES), ' and ', ui('Layout', CATEGORIES), '.'],
                        ['A question type. Click it to add that kind of question to your form. Each type is explained in this reference, from ', link('Text questions', '#types-text'), ' onwards.'],
                        [ui('Adv', PALETTE), ' — an advanced type: it works like any other, and its settings are more technical. See ', link('What each type can do', '#types-readiness'), '.'],
                    ),
                ],
            },
            {
                id: 'adding',
                title: 'What happens when you click a type',
                blocks: [
                    list(
                        ['The new question goes into the section you selected, or into the section of the question you selected. With nothing selected it goes in at the top level, outside any section.'],
                        ['It is added at the end of that section.'],
                        ['It is selected straight away, so its settings open in the right panel.'],
                        ['Its label starts as the name of its type — for example “Single choice”. Change it to your real question in ', ui('Label', CONFIG), '.'],
                        ['It starts as ', ui('Optional', PRESENTER), '.'],
                    ),
                    p(
                        'Two entries cover two formats each. ', ui('Text', CATEGORIES), ' adds a ', ui('Short text', FIELD_TYPES), ' question, which you can switch to ',
                        ui('Long text', FIELD_TYPES), '; ', ui('Number', CATEGORIES), ' adds a ', ui('Whole number', FIELD_TYPES), ' question, which you can switch to ',
                        ui('Decimal number', FIELD_TYPES), '. The switch is on the right panel’s ', ui('Basics', CONFIG), ' tab.',
                    ),
                    p('On a phone or a narrow window, the left panel is the ', ui('Add', BUILDER), ' view of the switch at the top of the builder.'),
                ],
            },
            {
                id: 'library',
                title: 'The question library',
                blocks: [
                    p('The library keeps questions you set up once — with their wording, choices and rules — so you can reuse them in any form of your workspace.'),
                    steps(
                        ['To save a question: select it, open its ', ui('Advanced', CONFIG), ' tab, and click ', ui('Save to library', CONFIG), '.'],
                        ['To reuse one: click ', ui('Library', BUILDER), ' at the top of the left panel. The ', ui('Question library', LIBRARY), ' lists your saved questions.'],
                        ['Type in ', ui('Search questions', LIBRARY), ' to find one, then click it. It is added to the selected section, like a new question.'],
                    ),
                    p('Until something is saved, the library says “', ui('No saved questions', LIBRARY), '”. Click ', ui('Fields', BUILDER), ' to go back to the question types.'),
                ],
            },
        ],
    },
    {
        id: 'right-panel',
        title: 'The right panel: settings',
        summary: 'The settings of the question or section you selected, in tabs.',
        sections: [
            {
                id: 'right-panel-tour',
                title: 'What is in the right panel',
                blocks: [
                    figure(
                        '/images/help/right-panel.png',
                        'The builder’s right panel for a Single choice question: the tabs Basics, Options, Validation and Advanced at the top, then the Question type row with its Change type button, and the Label, Help text, Placeholder and Requiredness settings. Numbered badges mark the parts described below.',
                        'The right panel of the builder, showing the Basics tab of a Single choice question.',
                        RIGHT_PICTURE.width,
                        RIGHT_PICTURE.height,
                    ),
                    steps(
                        ['The tabs. Every question has ', ui('Basics', CONFIG), ' and ', ui('Advanced', CONFIG), '; most also have ', ui('Validation', CONFIG), ', and some have one tab of their own — here, ', ui('Options', CONFIG), '.'],
                        [ui('Question type', TYPE_CONTROL), ' — the kind of question, with ', ui('Change type', TYPE_CONTROL), ' to turn it into another kind.'],
                        [ui('Label', CONFIG), ' — the question as respondents read it.'],
                        [ui('Help text', CONFIG), ' — a hint shown under the question.'],
                        [ui('Placeholder', CONFIG), ' — sample text shown in an empty box.'],
                        [ui('Requiredness', CONFIG), ' — whether the question must be answered.'],
                    ),
                    p('With nothing selected, the right panel says “', ui('Select a field or section to configure it.', CONFIG), '” On a phone or a narrow window, it is the ', ui('Settings', BUILDER), ' view.'),
                ],
            },
            {
                id: 'tab-list',
                title: 'Which tabs a question has',
                blocks: [
                    list(
                        [ui('Basics', CONFIG), ' — every question. See ', link('Basics', '#basics-tab'), '.'],
                        [ui('Options', CONFIG), ' — ', ui('Single choice', FIELD_TYPES), ', ', ui('Multiple choice', FIELD_TYPES), ', ', ui('Dropdown', FIELD_TYPES), ' and ', ui('Likert scale', FIELD_TYPES), ': the choices.'],
                        [ui('Levels', CONFIG), ' — ', ui('Cascading select', FIELD_TYPES), ': its levels and their choices.'],
                        [ui('Grid', CONFIG), ' — ', ui('Matrix (grid)', FIELD_TYPES), ' and ', ui('Likert matrix', FIELD_TYPES), ': rows and columns.'],
                        [ui('Map', CONFIG), ' — ', ui('GPS point', FIELD_TYPES), ', ', ui('GPS line', FIELD_TYPES), ' and ', ui('GPS area', FIELD_TYPES), ': how a location is captured.'],
                        [ui('Media', CONFIG), ' — ', ui('File upload', FIELD_TYPES), ', ', ui('Photo', FIELD_TYPES), ', ', ui('Audio', FIELD_TYPES), ' and ', ui('Video', FIELD_TYPES), ': what may be uploaded; ', ui('Signature', FIELD_TYPES), ': how it is signed.'],
                        [ui('Prefill', CONFIG), ' — ', ui('Hidden field', FIELD_TYPES), ': where its value comes from.'],
                        [ui('Content', CONFIG), ' — ', ui('Note / label', FIELD_TYPES), ': what it shows.'],
                        [ui('Validation', CONFIG), ' — every question that takes an answer. See ', link('Validation', '#validation-tab'), '.'],
                        [ui('Advanced', CONFIG), ' — every question. See ', link('Advanced', '#advanced-tab'), '.'],
                    ),
                ],
            },
            {
                id: 'basics-tab',
                title: 'Basics',
                blocks: [
                    list(
                        [ui('Question type', TYPE_CONTROL), ' and ', ui('Change type', TYPE_CONTROL), ' — see ', link('Change a question’s type', '#change-type'), '.'],
                        ['A ', ui('format', TYPE_CONTROL), ' switch, on text and number questions only: ', ui('Short text', FIELD_TYPES), ' or ', ui('Long text', FIELD_TYPES), ', ', ui('Whole number', FIELD_TYPES), ' or ', ui('Decimal number', FIELD_TYPES), '.'],
                        [ui('Label', CONFIG), ', ', ui('Help text', CONFIG), ' and ', ui('Placeholder', CONFIG), ' — the wording respondents see. Each can show an earlier answer: click ', ui('Insert a question', 'resources/js/components/builder/ReferenceInput.vue'), ' under the box, or type @, and pick it from the list, which shows each question’s code.'],
                        [ui('Calculation formula', CALCULATION_EDITOR), ', with its ', ui('Result type', CALCULATION_EDITOR), ' and ', ui('Build it', CALCULATION_EDITOR), ' — ', ui('Calculated', FIELD_TYPES), ' questions only. See ', link('Calculated', '#type-calculated'), '.'],
                        [
                            ui('Requiredness', CONFIG), ': ', ui('Optional', PRESENTER), ', ', ui('Required', PRESENTER), ' or ', ui('Conditional', PRESENTER),
                            '. Conditional opens a box, ', ui('Required when…', CONFIG), ': click ', ui('Add condition', CONFIG), ' and choose when the answer becomes required — for example, when an earlier question is answered Yes.',
                        ],
                    ),
                ],
            },
            {
                id: 'change-type',
                title: 'Change a question’s type',
                blocks: [
                    steps(
                        ['Select the question, and on ', ui('Basics', CONFIG), ' click ', ui('Change type', TYPE_CONTROL), '.'],
                        ['In the window “', ui('Change question type', CONVERT), '”, pick the new type in ', ui('Change to', CONVERT), '.'],
                        ['Read what will change, under ', ui('Things to check', CONVERT), ' and ', ui('Elsewhere in the form', CONVERT), ' — for example, choices that will be removed.'],
                        ['Confirm. Its ', ui('Field key', CONFIG), ' stays the same, so conditions and formulas that use the question keep working. ', ui('Undo', BUILDER), ' changes it back.'],
                    ),
                    p(
                        'A question changes only into a close relative: text types into each other; ', ui('Whole number', FIELD_TYPES), ' and ', ui('Decimal number', FIELD_TYPES),
                        '; the date and time types; ', ui('Single choice', FIELD_TYPES), ', ', ui('Multiple choice', FIELD_TYPES), ', ', ui('Dropdown', FIELD_TYPES), ' and ', ui('Likert scale', FIELD_TYPES),
                        '; the GPS types; the media types; and the two grids. A ', ui('Yes / No', FIELD_TYPES), ' question becomes a ', ui('Single choice', FIELD_TYPES), ' with the choices Yes and No. ',
                        ui('Calculated', FIELD_TYPES), ', ', ui('Duration', FIELD_TYPES), ' and ',
                        ui('Cascading select', FIELD_TYPES), ' can only become a ', ui('Note / label', FIELD_TYPES), ' or a ', ui('Hidden field', FIELD_TYPES), '. A ', ui('Page break', FIELD_TYPES), ' cannot change.',
                    ),
                ],
            },
            {
                id: 'validation-tab',
                title: 'Validation',
                blocks: [
                    p('Rules stop a wrong answer before the form is sent. Click ', ui('Add rule', VALIDATION), ', choose the kind of rule, fill in its value, and write the message respondents see when it fails in “', ui('Error message shown to the respondent', VALIDATION), '”.'),
                    p('The kinds of rule, and the questions they suit:'),
                    list(
                        [ui('Minimum value', RULE_TYPES), ' and ', ui('Maximum value', RULE_TYPES), ' — numbers and ', ui('Likert scale', FIELD_TYPES), '. For example, an age from 0 to 120. For a number, set the rule to “', ui('Another question’s answer', VALIDATION), '” to use an answer instead of a fixed number — for example, “days off” at most “days worked”.'],
                        [ui('No negative numbers', RULE_WORDS), ' — numbers only. It refuses any answer below zero, and needs no value.'],
                        [ui('Minimum length', RULE_TYPES), ' and ', ui('Maximum length', RULE_TYPES), ' — text. For example, an ID of exactly 10 characters.'],
                        [ui('Must match a pattern', RULE_TYPES), ' — text in a fixed shape, such as a phone number. Email, phone and web-address questions start with one.'],
                        [ui('Greater than another question', RULE_TYPES), ' and ', ui('Less than another question', RULE_TYPES), ' — numbers compared with another answer, such as “children” less than “household size”; ', ui('At least another question', RULE_TYPES), ' and ', ui('At most another question', RULE_TYPES), ' also let an equal answer through. While the other question is still blank, all four let the answer through.'],
                        [
                            ui('Required when a condition holds', RULE_TYPES), ', ', ui('Required with another question', RULE_TYPES), ', ', ui('Skipped when a condition holds', RULE_TYPES), ' and ',
                            ui('Skipped with another question', RULE_TYPES), ' — any question that takes an answer.',
                        ],
                    ),
                    p(
                        'With two or more rules, choose whether ', ui('The answer must pass', VALIDATION), ' ', ui('all of these rules', VALIDATION), ' or ', ui('any one of these rules', VALIDATION),
                        '. A rule the question cannot use is marked “', ui('not available for this question', VALIDATION), '”.',
                    ),
                ],
            },
            {
                id: 'advanced-tab',
                title: 'Advanced',
                blocks: [
                    list(
                        [ui('Field key', CONFIG), ' — the question’s short name, used in exports, formulas and conditions. Lowercase and unique in the form: if another question already uses the key you type, the builder says so and saves the next free one (for example age_2).'],
                        [ui('Section', CONFIG), ' — move the question into a section, or to ', ui('No section (top level)', CONFIG), '.'],
                        [
                            '“', ui('Show this question only when…', CONFIG), '” — ask the question only when earlier answers make it relevant. Click ', ui('Add condition', CONDITIONS),
                            ', choose a question, a check such as ', ui('is', CONDITION_ROW), ', ', ui('is not', CONDITION_ROW), ', ', ui('is blank', CONDITION_ROW), ' or ', ui('includes', CONDITION_ROW),
                            ', and a value. Combine several with ', ui('All of', CONDITIONS), ' or ', ui('Any of', CONDITIONS), ', or nest them with ', ui('Add group', CONDITIONS),
                            '. ', ui('Edit as text', CONDITION_EDITOR), ' shows the same condition as a formula, with ', ui('Insert a question', 'resources/js/components/builder/ReferenceInput.vue'), ' to add a question by its code. See ', link('Show or skip questions', '#logic'), '.',
                        ],
                        [ui('Default value', CONFIG), ' — an answer filled in before the respondent starts, which they can change. ', ui('Fixed', CONFIG), ' is the answer as you write it (a choice question offers its own choices); ', ui('Formula', CONFIG), ' works it out once, when the form opens — ', ui('Insert a question', 'resources/js/components/builder/ReferenceInput.vue'), ' offers the questions before this one and dates such as Today and Tomorrow. Staff keying a new response get it too; a saved draft keeps what was saved.'],
                        [ui('Contains personal data (PII)', CONFIG), ' and ', ui('Sensitive', CONFIG), ' — mark answers that identify or could harm a person, so they are handled with care.'],
                        [ui('Indexed for reporting', CONFIG), ' — make the answer available to reports; then choose its ', ui('Indexed data type', CONFIG), ': ', ui('Text', INDEXED), ', ', ui('Number', INDEXED), ', ', ui('Boolean', INDEXED), ', ', ui('Date', INDEXED), ' or ', ui('Datetime', INDEXED), '.'],
                        [ui('Save to library', CONFIG), ' — keep a copy to reuse in other forms. See ', link('The question library', '#library'), '.'],
                    ),
                ],
            },
            {
                id: 'section-settings',
                title: 'When a section is selected',
                blocks: [
                    p('Click a section’s heading in ', ui('Structure', BUILDER), ' to see its settings.'),
                    list(
                        [ui('Basics', CONFIG), ': ', ui('Section title', CONFIG), ' (the heading respondents see), ', ui('Section key', CONFIG), ' (its short name) and ', ui('Description', CONFIG), '.'],
                        [
                            ui('Advanced', CONFIG), ': ', ui('Repeatable group', CONFIG), ' lets respondents fill the section in more than once — one entry per household member, for example. ',
                            ui('Min instances', CONFIG), ' and ', ui('Max instances', CONFIG), ' set how many entries are allowed. ',
                            ui('Layout', REPEAT_LAYOUT), ' draws the entries as ', ui('A card per entry', REPEAT_LAYOUT), ', or as ', ui('A table, a row per entry', REPEAT_LAYOUT),
                            ' — every question a column. A table holds text, numbers, dates and choices; a section with any other question stays as cards, and the setting names it. Phones show a card per entry either way, and the response PDF and the paper form draw the table too.',
                        ],
                        ['“', ui('Show this section only when…', CONFIG), '” — show or skip the whole section, like a question.'],
                    ),
                    tip('warning', 'Grids, GPS, photo and file questions, and hidden fields cannot sit inside a repeatable section — publishing refuses them there.'),
                ],
            },
        ],
    },
    {
        id: 'types-text',
        title: 'Text questions',
        summary: 'Short and long text, email addresses, phone numbers and web addresses.',
        sections: [
            {
                id: 'type-text',
                title: 'Text: Short text and Long text',
                blocks: [
                    p(
                        'Click ', ui('Text', CATEGORIES), ' in the left panel. It adds a ', ui('Short text', FIELD_TYPES), ' question: one line, for a name or an ID number. For several lines — a comment, an address — switch it to ',
                        ui('Long text', FIELD_TYPES), ' on ', ui('Basics', CONFIG), '.',
                    ),
                    list(
                        ['Tabs: ', ui('Basics', CONFIG), ', ', ui('Validation', CONFIG), ', ', ui('Advanced', CONFIG), '.'],
                        ['Useful rules: ', ui('Minimum length', RULE_TYPES), ', ', ui('Maximum length', RULE_TYPES), ', ', ui('Must match a pattern', RULE_TYPES), '.'],
                    ),
                    p(
                        'A Short text can suggest answers as people type: on ', ui('Basics', CONFIG), ', under ', ui('Suggestions come from', SUGGESTIONS), ', pick ',
                        ui('A list file', SUGGESTIONS), ' or ', ui("Another form's answers", SUGGESTIONS), '. People can still type any answer; the suggestions only help them type it the same way.',
                    ),
                ],
            },
            {
                id: 'type-email',
                title: 'Email',
                blocks: [
                    p(ui('Email', FIELD_TYPES), ' is a box for an email address; phones show an email keyboard. It starts with a ', ui('Must match a pattern', RULE_TYPES), ' rule whose message is “', ui('Enter a valid email address.', DEFAULT_RULES), '”'),
                ],
            },
            {
                id: 'type-phone',
                title: 'Phone',
                blocks: [
                    p(ui('Phone', FIELD_TYPES), ' is a box for a phone number; phones show a number keypad. It starts with a pattern rule whose message is “', ui('Enter a valid phone number.', DEFAULT_RULES), '” If your numbers are written differently, change or remove the rule on ', ui('Validation', CONFIG), '.'),
                ],
            },
            {
                id: 'type-url',
                title: 'URL',
                blocks: [
                    p(ui('URL', FIELD_TYPES), ' is a box for a web address. It starts with a pattern rule whose message is “', ui('Enter a valid web address, starting with http:// or https://.', DEFAULT_RULES), '”'),
                ],
            },
        ],
    },
    {
        id: 'types-number',
        title: 'Number questions',
        summary: 'Whole numbers, decimal numbers, and answers worked out from other answers.',
        sections: [
            {
                id: 'type-number',
                title: 'Number: Whole number and Decimal number',
                blocks: [
                    p(
                        'Click ', ui('Number', CATEGORIES), ' in the left panel. It adds a ', ui('Whole number', FIELD_TYPES), ' question — an age, a count. For amounts like 2.5, switch it to ',
                        ui('Decimal number', FIELD_TYPES), ' on ', ui('Basics', CONFIG), '.',
                    ),
                    list(
                        ['Negative answers are allowed until you add the rule ', ui('No negative numbers', RULE_WORDS), ' on the ', ui('Validation', CONFIG), ' tab — add it for counts and ages.'],
                        ['Useful rules: ', ui('Minimum value', RULE_TYPES), ', ', ui('Maximum value', RULE_TYPES), ', ', ui('Greater than another question', RULE_TYPES), ', ', ui('Less than another question', RULE_TYPES), '.'],
                    ),
                ],
            },
            {
                id: 'type-calculated',
                title: 'Calculated',
                blocks: [
                    p(
                        'An answer worked out from other answers — a total, an age from a birth date. Respondents do not see it unless you choose ', ui('Always', CONFIG),
                        ' under ', ui('Show to respondents', CONFIG), ' on its ', ui('Advanced', CONFIG), ' tab: then they see its value, read-only, as they answer. Either way it is worked out again when the response is saved.',
                    ),
                    list(
                        ['Choose its ', ui('Result type', CALCULATION_EDITOR), ' — ', ui('Number', CALCULATION_EDITOR), ', ', ui('Text', CALCULATION_EDITOR), ', ', ui('Date', CALCULATION_EDITOR), ' or ', ui('Duration', CALCULATION_EDITOR), '. It decides how the value is shown: a number to its ', ui('Decimal places', CALCULATION_EDITOR), ', a duration with its unit, such as 34 years.'],
                        [ui('Build it', CALCULATION_EDITOR), ' builds the formula for you: pick a ', ui('Preset', CALCULATION_EDITOR), ' — Sum, Difference, Average, Count answered, If … then …, Join answers, Age from a birth date, Duration between two dates or times, or A date N days from a date — then pick its questions from the lists. The formula it writes is shown beneath it.'],
                        [ui('Formula', CALCULATION_EDITOR), ' lets you type your own in ', ui('Calculation formula', CALCULATION_EDITOR), '; typing in the formula under a preset switches to it. Click ', ui('Insert a question', 'resources/js/components/builder/ReferenceInput.vue'), ' or type @ to add another question by its ', ui('Field key', CONFIG), ', which is written inside ${ } — for example ', ui('e.g. ${quantity} * ${unit_price}', CALCULATION_EDITOR), '.'],
                        ['Use + − * / for arithmetic, and sum(), round(), if(), count(), coalesce() and concat(); for dates, today(), age(), date_diff() and date_add(). A duration counts whole units only: from 8:30 to 17:00 is 8 hours.'],
                        ['A calculation may use another one, and a condition or a rule may read a calculation. Two calculations that use each other can never be worked out, so publishing refuses them.'],
                        ['It has no ', ui('Placeholder', CONFIG), ' or ', ui('Requiredness', CONFIG), ', and publishing refuses it without a formula.'],
                    ),
                ],
            },
        ],
    },
    {
        id: 'types-date',
        title: 'Date and time questions',
        summary: 'Dates, times, and both together.',
        sections: [
            {
                id: 'type-date-time',
                title: 'Date, Time, and Date & time',
                blocks: [
                    p(ui('Date', FIELD_TYPES), ' shows a calendar, ', ui('Time', FIELD_TYPES), ' a clock, and ', ui('Date & time', FIELD_TYPES), ' both. Respondents pick or type the value. Dates and times can be compared in conditions — for example, show a question only when a visit date is after another date.'),
                    p('Only the four required-or-skipped rules apply to them on ', ui('Validation', CONFIG), '.'),
                ],
            },
            {
                id: 'type-duration',
                title: 'Duration',
                blocks: [
                    tip('warning', 'This type is retired: you can no longer add one. Respondents cannot answer a Duration question — the form shows “', NOT_YET, '” Use a ', ui('Whole number', FIELD_TYPES), ' question, such as “minutes”, for now; a duration will be worked out by a calculation.'),
                ],
            },
        ],
    },
    {
        id: 'types-choice',
        title: 'Choice questions',
        summary: 'Pick one, pick several, pick from a list, yes or no, and lists that narrow each other down.',
        sections: [
            {
                id: 'type-single-choice',
                title: 'Single choice',
                blocks: [
                    p('Round buttons; the respondent picks exactly one, and can clear it again.'),
                    steps(
                        ['On the ', ui('Options', CONFIG), ' tab, click ', ui('Add option', CHOICES), ' once for each choice.'],
                        ['Fill in each choice’s ', ui('Label', CHOICES), ' (what respondents read) and ', ui('Value', CHOICES), ' (the short code saved in the results and exports).'],
                        ['Pick a ', ui('Choice layout', CONFIG), ': ', ui('One per line (default)', CONFIG), ', ', ui('Side by side', APPEARANCE), ' or ', ui('In columns', APPEARANCE), '.'],
                    ),
                    p(
                        'To let respondents write in an answer of their own, turn on ', ui('Add “Other, please specify”', OTHER_SPECIFY), ' on the ', ui('Options', CONFIG),
                        ' tab. It adds the choice “Other” and a question “Please specify” right after this one; respondents see it only when they pick Other, and must answer it then. Turning it off removes both. ',
                        ui('Multiple choice', FIELD_TYPES), ' and ', ui('Dropdown', FIELD_TYPES), ' have it too.',
                    ),
                    p(
                        'Its choices can also come from another form: under ', ui('Choices come from', LINKED), ', pick ', ui("Another form's answers", LINKED),
                        ', then the form and the question. That form’s owner has to share its answers first, in its ', ui('Data sharing', SETTINGS), ' settings. The default is ', ui('Typed here', LINKED), '.',
                    ),
                    p(
                        'Or from a list file: pick ', ui('A list file', LINKED), ', then a list this form holds, or ', ui('Upload a CSV or Excel file', LINKED),
                        ' there. The file needs a “name” column (the code saved) and a “label” column (what respondents read); an Excel file’s first sheet is read. Publishing turns the list into the choices, up to 5,000. A long list is easier as a Dropdown, and the paper form prints every choice.',
                    ),
                    p(
                        'Choices from a list file or another form can be narrowed by what a third form has recorded: under ', ui('Show only', CHOICE_FILTER), ', pick ',
                        ui('Only those another form has an answer for', CHOICE_FILTER), ' or ', ui('Only those another form has no answer for', CHOICE_FILTER),
                        ', then the form and its question. Respondents see only those choices; an answer already given is never refused, and if that form cannot be checked every choice is shown with a note.',
                    ),
                ],
            },
            {
                id: 'type-multiple-choice',
                title: 'Multiple choice',
                blocks: [
                    p(
                        'Tick boxes; the respondent ticks every choice that applies. Its ', ui('Options', CONFIG), ' tab works like ', link('Single choice', '#type-single-choice'),
                        ', including ', ui('Choice layout', CONFIG), ' and where its choices come from: typed here, a list file, or another form’s answers. In a condition, check it with ', ui('includes', CONDITION_ROW), ' or ', ui('does not include', CONDITION_ROW), '.',
                    ),
                ],
            },
            {
                id: 'type-dropdown',
                title: 'Dropdown',
                blocks: [
                    p(
                        'A list that opens when tapped; the respondent picks one. Better than ', ui('Single choice', FIELD_TYPES), ' for long lists. Its ', ui('Options', CONFIG),
                        ' tab works like Single choice’s, without a layout, and its choices can also come from a list file or another form.',
                    ),
                ],
            },
            {
                id: 'type-yes-no',
                title: 'Yes / No',
                blocks: [
                    p('This type is retired: you can no longer add one, and a question already on a form keeps working. To ask yes or no, add a ', ui('Single choice', FIELD_TYPES), ' and press ', ui('Add Yes and No', CHOICES), ' on its ', ui('Options', CONFIG), ' tab. An existing one shows ', ui('Change to Single choice', TYPE_CONTROL), ', which keeps its answers.'),
                    p('On the form it shows two buttons, ', ui('Yes', FIELD_INPUT), ' and ', ui('No', FIELD_INPUT), '.'),
                ],
            },
            {
                id: 'type-cascading',
                title: 'Cascading select',
                blocks: [
                    p('A chain of lists, each narrowed by the one before — Region, then Province, then City. Set it up on the ', ui('Levels', CONFIG), ' tab:'),
                    steps(
                        ['Click ', ui('Add level', CASCADING), ' once per list, from the broadest to the most specific.'],
                        ['For each level, either type its choices in the tab (', ui('Typed in below', CASCADING), ') or upload them in ', ui('Choices from a CSV or Excel file', CASCADING), '.'],
                        ['Typed choices: under each choice, pick its level and, below the top level, the choice above it (', ui('No parent (top level)', CASCADING), ' for the first level).'],
                    ),
                    tip('info', 'Use CSV or Excel files for long lists such as every barangay. Either every level comes from a file, or none does. Excel turns a code like 01 into 1, so format the code columns as Text before you type them, or use letters.'),
                    p(
                        'For places in the Philippines there is nothing to upload: ',
                        ui('Philippine places (PSGC)', CASCADING),
                        ' sets four levels — Region, Province or district, City or municipality, Barangay — from the Philippine Statistics Authority’s list. Publishing writes the newest list on this server into the form, so a published form keeps its quarter; publish again to pick up a later one. Remove levels from the bottom to stop at the city or the province.',
                    ),
                    p(
                        ui('Fill in a level that has only one choice', CASCADING),
                        ' fills such a level in for the person — a city outside any province is its own only choice at the city level, so they pick the city once. The last level is always theirs to pick.',
                    ),
                    p(
                        'A level can show only what another form has recorded — only the barangays with a report in form X, or only those with none yet: ',
                        ui('Show only', CHOICE_FILTER), ' under the level. The levels above narrow to match, so nobody picks a city with no barangay left. The other form shares its cascading question for this in its Data sharing settings, under ',
                        ui('Only the questions I choose', DATA_SHARING), ' — never through every question. A Philippine places level is narrowed by the same level of the other form’s Philippine places question.',
                    ),
                ],
            },
        ],
    },
    {
        id: 'types-rating',
        title: 'Rating questions',
        summary: 'A rating scale, and several statements rated on the same scale.',
        sections: [
            {
                id: 'type-likert-scale',
                title: 'Likert scale',
                blocks: [
                    p('A row of points, such as 1 “Strongly disagree” to 5 “Strongly agree”; the respondent picks one. The points sit on one line, each with its words underneath.'),
                    list(
                        ['It starts with no points. Add them on the ', ui('Options', CONFIG), ' tab with ', ui('Add option', CHOICES), ', lowest first.'],
                        ['Use numbers as each point’s ', ui('Value', CHOICES), ', so answers can be added up and checked with ', ui('Minimum value', RULE_TYPES), ' and ', ui('Maximum value', RULE_TYPES), '.'],
                    ),
                ],
            },
            {
                id: 'type-likert-matrix',
                title: 'Likert matrix',
                blocks: [
                    p('Several statements, each rated on the same scale, in one table: the scale’s words across the top and one round button for each statement and point. On a phone each statement becomes a card with its scale on one line. On the ', ui('Grid', CONFIG), ' tab:'),
                    list(
                        [ui('Rows come from', LINKED), ' — ', ui('Typed here', LINKED), ', ', ui('A list file', LINKED), ' or ', ui("Another form's answers", LINKED), '. Rows from a list or a form are copied in when you publish; publish again to pick up new answers.'],
                        [ui('Rows', LIKERT_MATRIX), ' — the statements to rate.'],
                        [ui('Scale points', LIKERT_MATRIX), ' — the rating scale shared by every row, from lowest to highest.'],
                        [ui('Layout', GRID_LAYOUT), ' — ', ui('Table where it fits', GRID_LAYOUT), ', or ', ui('Cards everywhere', GRID_LAYOUT), '.'],
                    ),
                ],
            },
        ],
    },
    {
        id: 'types-location',
        title: 'Location questions',
        summary: 'A place, a path, or an area on the map.',
        sections: [
            {
                id: 'type-gps',
                title: 'GPS point, GPS line and GPS area',
                blocks: [
                    list(
                        [ui('GPS point', FIELD_TYPES), ' — one place, such as a house.'],
                        [ui('GPS line', FIELD_TYPES), ' — a path of several points, such as a road.'],
                        [ui('GPS area', FIELD_TYPES), ' — a boundary, such as a field.'],
                    ),
                    p('Respondents use their phone’s location, tap the map, or type the coordinates. On the ', ui('Map', CONFIG), ' tab:'),
                    list(
                        [ui('Capture altitude', GEO), ' — also record the height (GPS point only).'],
                        [ui('Accuracy target (m)', GEO), ' — remind respondents when the location is less accurate than this. A reminder only.'],
                        [ui('Default map view', GEO), ' — where the map starts: ', ui('Latitude', GEO), ', ', ui('Longitude', GEO), ' and ', ui('Zoom level', GEO), ' (0 shows the world, 22 a building).'],
                    ),
                    tip('info', 'Location questions cannot be used in conditions or formulas, or inside a repeatable section. In Preview they are shown but cannot be answered — try them on the live form.'),
                ],
            },
        ],
    },
    {
        id: 'types-media',
        title: 'Photos, files and signatures',
        summary: 'Uploads and captures from the phone’s camera and microphone.',
        sections: [
            {
                id: 'type-media',
                title: 'File upload, Photo, Audio and Video',
                blocks: [
                    p('The respondent uploads a file, or takes a photo or a recording with their phone. On the ', ui('Media', CONFIG), ' tab:'),
                    list(
                        [ui('Accepted file types', MEDIA), ' — for example image/* for any picture, or application/pdf. Blank accepts any file.'],
                        [ui('Maximum file size (MB)', MEDIA), ' — the largest file allowed.'],
                        [ui('Minimum', MEDIA), ' and ', ui('Maximum', MEDIA), ' — how many files. A blank maximum means one.'],
                        [ui('Open the device camera / microphone by default', MEDIA), ' — Photo, Audio and Video only.'],
                    ),
                    tip('info', 'These questions cannot sit inside a repeatable section. In Preview they are shown but cannot be answered — try them on the live form.'),
                ],
            },
            {
                id: 'type-signature',
                title: 'Signature',
                blocks: [
                    p('Respondents sign with a finger, a pen or the mouse, on the web form and in manual entry. When they lift the pen for a moment the signature is saved; ', ui('Clear', SIGNATURE_PAD), ' starts again.'),
                    p('The response shows the signature as it was drawn, and so does its PDF. In a spreadsheet export the cell gives the file’s name and a link to open it; you sign in to open it. The paper form prints a line to sign on.'),
                    p('The ', ui('Media', CONFIG), ' tab says ', ui('How it is signed', MEDIA), ': a signature has no file settings.'),
                    tip('info', 'A form with a Signature cannot be scanned: its paper copies are keyed in. A Signature cannot sit inside a repeatable section. In Preview you can draw, but the signature is not saved.'),
                ],
            },
        ],
    },
    {
        id: 'types-layout',
        title: 'Layout items',
        summary: 'Text to read, page breaks, hidden values and grids.',
        sections: [
            {
                id: 'type-note',
                title: 'Note / label',
                blocks: [
                    p('Text, headings or a picture for the respondent to read. It asks nothing. Build it on the ', ui('Content', CONFIG), ' tab by adding blocks:'),
                    list(
                        [ui('Heading', CONTENT), ' — a ', ui('Large heading', CONTENT), ' or a ', ui('Small heading', CONTENT), '.'],
                        [ui('Paragraph', CONTENT), ' — text, with ', ui('Bold', CONTENT), ', ', ui('Italic', CONTENT), ' and ', ui('Link', CONTENT), ' buttons.'],
                        [ui('Callout', CONTENT), ' — a coloured box; choose its ', ui('Kind of callout', CONTENT), '.'],
                        [ui('Divider', CONTENT), ' — a line across the page.'],
                        [ui('Add an image', CONTENT), ' — a picture. Give it a ', ui('Description', CONTENT), ' for people who cannot see it; publishing refuses an image without one.'],
                    ),
                    p('The content is shown in the form’s default language only.'),
                ],
            },
            {
                id: 'type-page-break',
                title: 'Page break',
                blocks: [
                    p(
                        'Starts a new page when the form is shown ', ui('Step by step', PAGES), ' (in ', link('Form settings', '#settings'), '). It shows nothing itself. On ', ui('Advanced', CONFIG),
                        ', a show condition makes the break happen only when that condition holds.',
                    ),
                ],
            },
            {
                id: 'type-hidden',
                title: 'Hidden field',
                blocks: [
                    p('A value saved with every response but never shown — the team or district a link was sent to, for example. On the ', ui('Prefill', CONFIG), ' tab, choose ', ui('Where the value comes from', PREFILL), ':'),
                    list(
                        [ui('Nothing (leave empty)', PREFILL), '.'],
                        [ui('A fixed value', PREFILL), ' — the same ', ui('Fixed value', PREFILL), ' on every response.'],
                        [ui('A value from the link', PREFILL), ' — read from the form’s web address, under the ', ui('Link parameter name', PREFILL), ' you choose. Send each team its own link.'],
                    ),
                    tip('warning', 'Keep a hidden field ', ui('Optional', PRESENTER), ' and without rules: publishing refuses one that is required or has a rule.'),
                ],
            },
            {
                id: 'type-matrix',
                title: 'Matrix (grid)',
                blocks: [
                    p('A table of questions: each row is asked in every column, and each column takes its own kind of answer. It is a table on a wide screen and a card per row on a phone. On the ', ui('Grid', CONFIG), ' tab:'),
                    list(
                        [ui('Rows come from', LINKED), ' — ', ui('Typed here', LINKED), ', ', ui('A list file', LINKED), ' (each row of the file a row of the grid, up to 200) or ', ui("Another form's answers", LINKED), '.'],
                        [ui('Rows', MATRIX), ' — each row is a question.'],
                        [ui('Columns', MATRIX), ' — each column is asked in every row. Under each one: its ', ui('Type', MATRIX), ' (one choice, several choices, short text, a whole or decimal number, or a date), ', ui('Required in every row', MATRIX), ', and for a choice column where its choices come from — typed, a list file or another form’s answers.'],
                        [ui('Cell choices come from', LINKED), ' and ', ui('Cell choices', MATRIX), ' — the choices of every choice column with no list of its own.'],
                        [ui('Layout', GRID_LAYOUT), ' — ', ui('Table where it fits', GRID_LAYOUT), ', or ', ui('Cards everywhere', GRID_LAYOUT), '.'],
                    ),
                    tip('info', 'Rows and choices from a list file or another form are copied into the grid when you publish, so the paper form, the PDF and every export show them. Publish again to pick up new answers.'),
                    tip('info', 'Grids cannot be used in conditions or formulas, or inside a repeatable section. A spreadsheet gives every cell its own column, beside the grid’s one-line summary.'),
                ],
            },
        ],
    },
    {
        id: 'types-readiness',
        title: 'What each type can do',
        summary: 'Every question type, and where it works: the web form, manual entry, paper, the PDF, exports and more.',
        sections: [
            {
                id: 'type-readiness',
                title: 'Every type, on every surface',
                blocks: [
                    p('Every question type works on the web form and in manual entry unless the table says otherwise. A type marked ', ui('Adv', PALETTE), ' is an advanced one: it works like any other, and its settings are more technical.'),
                    readinessTable(),
                    list(
                        ['Retired — you can no longer add one. Questions already on a form keep working.'],
                        ['Form cannot be scanned — a form with this question cannot have its paper copies read automatically, so they are keyed in.'],
                        ['Named, not written — the paper form prints the question so nobody misses it, with no space to write an answer.'],
                        ['A dash — the question holds no answer, so there is nothing to show.'],
                    ),
                    p('The builder’s ', ui('Preview', BUILDER), ' draws each question as the web form does, except that photo, file and location questions are shown but not interactive, and a signature can be drawn but is not saved.'),
                    tip('info', 'Duration is retired: work a duration out with a ', ui('Calculated', FIELD_TYPES), ' question instead — a start, an end and the units.'),
                ],
            },
        ],
    },
    {
        id: 'publish-checks',
        title: 'Why Publish can say no',
        summary: 'The settings Publish checks, and how to fix each one.',
        sections: [
            {
                id: 'publish-refusals',
                title: 'What Publish checks',
                blocks: [
                    p('When a red box says “', ui('Not published', BUILDER), '”, it lists each problem. The usual ones:'),
                    list(
                        ['A choice question or ', ui('Likert scale', FIELD_TYPES), ' with no choices, or two choices with the same ', ui('Value', CHOICES), '.'],
                        ['A ', ui('Cascading select', FIELD_TYPES), ' with a level that has no choices, or a choice with no parent.'],
                        ['A grid with no rows, columns or cell choices.'],
                        ['A media question whose ', ui('Minimum', MEDIA), ' is more than its ', ui('Maximum', MEDIA), '.'],
                        [ui('Conditional', PRESENTER), ' requiredness with no condition under ', ui('Required when…', CONFIG), '.'],
                        ['A ', ui('Calculated', FIELD_TYPES), ' question with no formula.'],
                        [ui('Indexed for reporting', CONFIG), ' ticked with no ', ui('Indexed data type', CONFIG), '.'],
                        ['A note image with no ', ui('Description', CONTENT), '.'],
                        ['A rule that is missing its question or its comparison.'],
                        ['A ', ui('Hidden field', FIELD_TYPES), ' that is required or has a rule; a ', ui('Note / label', FIELD_TYPES), ' or ', ui('Page break', FIELD_TYPES), ' that is not ', ui('Optional', PRESENTER), '.'],
                        ['A grid, GPS, media or hidden question inside a ', ui('Repeatable group', CONFIG), '.'],
                    ),
                ],
            },
        ],
    },
];

export const manual: ManualChapter[] = [
    ...guide.map((chapter): ManualChapter => ({ ...chapter, part: 'guide' })),
    ...reference.map((chapter): ManualChapter => ({ ...chapter, part: 'reference' })),
];

/** Every on-screen label the manual quotes — what `manual.test.ts` checks against the files that draw them. */
export function quotedLabels(chapters: ManualChapter[] = manual): { label: string; from: string; at: string }[] {
    const found: { label: string; from: string; at: string }[] = [];
    for (const chapter of chapters) {
        for (const section of chapter.sections) {
            for (const block of section.blocks) {
                for (const run of blockSpans(block)) {
                    for (const span of run) {
                        if (span.kind === 'ui') {
                            found.push({ label: span.label, from: span.from, at: `${chapter.id}/${section.id}` });
                        }
                    }
                }
            }
        }
    }
    return found;
}

/** Every link the manual holds, with where it sits. */
export function manualLinks(chapters: ManualChapter[] = manual): { href: string; at: string }[] {
    const found: { href: string; at: string }[] = [];
    for (const chapter of chapters) {
        for (const section of chapter.sections) {
            for (const block of section.blocks) {
                for (const run of blockSpans(block)) {
                    for (const span of run) {
                        if (span.kind === 'link') {
                            found.push({ href: span.href, at: `${chapter.id}/${section.id}` });
                        }
                    }
                }
            }
        }
    }
    return found;
}

function blockSpans(block: ManualBlock): ManualSpan[][] {
    if (block.kind === 'figure') return [];
    if (block.kind === 'table') return block.rows.map((row) => row.head);
    return block.kind === 'steps' || block.kind === 'list' ? block.items : [block.spans];
}

/** Every picture the manual shows, with where it sits. */
export function manualFigures(chapters: ManualChapter[] = manual): { src: string; width: number; height: number; alt: string; at: string }[] {
    const found: { src: string; width: number; height: number; alt: string; at: string }[] = [];
    for (const chapter of chapters) {
        for (const section of chapter.sections) {
            for (const block of section.blocks) {
                if (block.kind === 'figure') {
                    found.push({ src: block.src, width: block.width, height: block.height, alt: block.alt, at: `${chapter.id}/${section.id}` });
                }
            }
        }
    }
    return found;
}
