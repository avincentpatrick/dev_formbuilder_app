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
    | { kind: 'tip'; tone: 'info' | 'success' | 'warning'; spans: ManualSpan[] };

export interface ManualSection {
    id: string;
    title: string;
    blocks: ManualBlock[];
}

export interface ManualChapter {
    id: string;
    title: string;
    summary: string;
    sections: ManualSection[];
}

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
const TYPE_CONTROL = 'resources/js/components/builder/FieldTypeControl.vue';
const FIELD_TYPES = 'app/Enums/FieldType.php';
const CATEGORIES = 'app/Enums/FieldCategory.php';
const PRESENTER = 'app/Services/Forms/BuilderPresenter.php';
const CHOICES = 'resources/js/components/builder/ChoicesEditor.vue';
const VALIDATION = 'resources/js/components/builder/ValidationEditor.vue';
const CONDITIONS = 'resources/js/components/builder/ConditionRows.vue';
const PREVIEW = 'resources/js/components/builder/PreviewPane.vue';
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

export const manual: ManualChapter[] = [
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
                        ' — click it to add a question straight into that section.',
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
                        [ui('Yes / No', FIELD_TYPES), ' — a simple yes-or-no question.'],
                        [ui('Date', FIELD_TYPES), ', ', ui('Time', FIELD_TYPES), ' and ', ui('Date & time', FIELD_TYPES), ' — a calendar or clock picker.'],
                        [ui('Email', FIELD_TYPES), ' and ', ui('Phone', FIELD_TYPES), ' — checked to look like an email address or a phone number.'],
                        [ui('Photo', FIELD_TYPES), ', ', ui('File upload', FIELD_TYPES), ' and ', ui('Signature', FIELD_TYPES), ' — the respondent attaches or draws something.'],
                        [ui('Note / label', FIELD_TYPES), ' — text, a heading or an image for the respondent to read. It asks nothing.'],
                        [ui('Page break', FIELD_TYPES), ' — starts a new page when the form is shown one step at a time.'],
                    ),
                    p('A type marked “Adv” is an advanced one. You can try it, but its settings are more technical.'),
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
                        ['Rename: double-click a question’s label in ', ui('Structure', BUILDER), ' and type. Press Enter to keep it.'],
                        ['Copy: ', ui('Duplicate field', CANVAS), ' adds a copy right below the question.'],
                        ['Delete: ', ui('Delete field', CANVAS), ' for a question, ', ui('Delete section', CANVAS), ' for a section. ', ui('Undo', BUILDER), ' brings it back.'],
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
                        ui('Basics', CONFIG), ' has a ', ui('Change type', TYPE_CONTROL), ' button. For a number you can also tick ',
                        ui('Allow negative numbers', TYPE_CONTROL), '.',
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
                            ') and an optional ', ui('Max responses', SCHEDULE), '. Click ', ui('Save schedule', SCHEDULE), '.',
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
    return block.kind === 'steps' || block.kind === 'list' ? block.items : [block.spans];
}
