export { default as MdsButton } from './components/Button/Button.vue';
export { default as MdsIconButton } from './components/IconButton/IconButton.vue';
export { default as MdsFormField } from './components/FormField/FormField.vue';
export { default as MdsTextInput } from './components/TextInput/TextInput.vue';
export { default as MdsTextarea } from './components/Textarea/Textarea.vue';
export { default as MdsSelect } from './components/Select/Select.vue';
export { default as MdsNumberInput } from './components/NumberInput/NumberInput.vue';
export { default as MdsPasswordInput } from './components/PasswordInput/PasswordInput.vue';
// The live password-requirement checklist (J3b). In the PACKAGE for the same coverage reason as
// FilterBar and TabNav below: Storybook globs `packages/design-system/src/**` only, so an app-tree
// component gets no story and no `checkA11y` scan at all (DSR §4.6.1). A component whose entire
// job is announcing state changes to somebody who cannot see them is the last one that should sit
// outside the accessibility gate. It renders the SERVER's list — see its docblock, and note that the
// `uncompromised` row's null pattern is deliberate and must never be given one.
export { default as MdsPasswordStrength } from './components/PasswordStrength/PasswordStrength.vue';
export type { PasswordRequirement } from './components/PasswordStrength/PasswordStrength.vue';
export {
    describedByWithStrength,
    passwordStrengthId,
} from './components/PasswordStrength/describedby';
export { default as MdsIcon } from './components/Icon/Icon.vue';
export { default as MdsCard } from './components/Card/Card.vue';
export { default as MdsStatTile } from './components/StatTile/StatTile.vue';
export { default as MdsEmptyState } from './components/EmptyState/EmptyState.vue';
export { default as MdsSegmentedControl } from './components/SegmentedControl/SegmentedControl.vue';
export { default as MdsSpinner } from './components/Spinner/Spinner.vue';
// The determinate half of DSR §3.9, beside the indeterminate one above (J4a). §3.9's governing rule is that
// a spinner is never used for an operation with a knowable fraction complete, so the two belong together
// here. It renders its label and its numeric value UNCONDITIONALLY: "a bar alone is not sufficient" is a
// rule this API makes unwriteable rather than merely documents, the same way MdsBadge's `dot` cannot be
// used to build the bare coloured disc §3.8 forbids. ⚠️ The step-count variant §3.9 also specifies is NOT
// built — see the as-built note there, and `resources/public-runtime/components/ProgressIndicator.vue`,
// which is its reference implementation and its first refactor target.
export { default as MdsProgress } from './components/Progress/Progress.vue';
export { type ProgressTone, type ProgressSize } from './components/Progress/Progress.vue';
// The initials chip (J4a). ALWAYS `aria-hidden`, and no prop changes that: an avatar in this system is
// always beside the person's visible name, and one that must CARRY a name is not an avatar but a link whose
// accessible name is the person. The reconsideration trigger is a CONSUMER — a stacked group, or an avatar
// with no adjacent name — of which there are none, the same discipline that kept `rowHref` off MdsDataTable.
// ⚠️ Monochrome on purpose: reusing `--mds-form-identity-*` measures 2.91:1 under white text in dark, and
// would put a PERSON and a FORM at 0° in a scale whose own suite proves every member is 30° from every
// other. There is no `src` either — nothing in this product stores a profile photo, and an image needs a
// different contract (alt text, a broken-image fallback) rather than one more prop.
export { default as MdsAvatar } from './components/Avatar/Avatar.vue';
export { type AvatarSize, type AvatarTone } from './components/Avatar/Avatar.vue';
export { default as MdsBadge } from './components/Badge/Badge.vue';
export { default as MdsBanner } from './components/Banner/Banner.vue';
export { type BannerTone } from './components/Banner/Banner.vue';
// The in-flow contextual message (J4a), and MdsBanner's SIBLING rather than its replacement. The split is
// four questions, each enforced by a prop that exists or does not — see DSR §3.7a and the docblock. Banner
// states a CONDITION in one line, is fixed-polite because the condition was already true when the page
// loaded, and has no dismiss because hiding a live fact is not a feature. Alert carries rich content, may
// opt into `assertive` for something that JUST happened, may be dismissed, and has a success tone.
// ⚠️ Do not "unify" them: Banner's role="status" argument is load-bearing for the impersonation surface,
// and position is NOT the discriminator — `SsoStatusCard` already mounts a Banner inside a card, correctly.
export { default as MdsAlert } from './components/Alert/Alert.vue';
export { type AlertTone } from './components/Alert/Alert.vue';
// The hover/focus label the collapsed sidebar rail has required since DSR §3.4 and §6 were written (J4b).
// ⚠️ IT TELEPORTS, AND THAT IS CORRECTNESS RATHER THAN CONVENIENCE: its consumer sits inside a box with
// `overflow-y: auto`, which per CSS Overflow 3 drags the other axis to `auto` too, so an in-flow bubble is
// clipped AND adds a scrollbar the document-level e2e overflow check cannot see. The teleported root
// therefore carries `data-mds-inert-exempt` — the second holder after MdsToastHost, and the reason §3.4.1's
// "that exemption belongs to the toast host alone" was narrowed in this increment.
// ⚠️ `aria-describedby` reaches the trigger through a SCOPED SLOT, never a wrapper attribute: the wrapper is
// not focusable, so an attribute there is never announced. Bind `trigger` onto the real control.
export { default as MdsTooltip } from './components/Tooltip/Tooltip.vue';
export { type TooltipPlacement } from './components/Tooltip/position';
// The anchored action menu (J4b), extracted from the account menu that had been carrying this behaviour
// alone in the application tree. ⚠️ THE MOVE BOUGHT SCRUTINY RATHER THAN REUSE: an app-tree component gets
// no story and therefore no accessibility scan, which is how a `role="menu"` holding non-menuitem children
// survived every increment since it was written. A menu owns ONLY menuitems; a header is a sibling, wired
// as the menu's description so entering it announces who is signed in.
// ⚠️ Escape binds on the component's own ROOT, not on `document` — MdsModal listens on its panel, an
// ancestor of any menu inside a dialog, so a document-level bubble listener is too late and closes the
// dialog instead. MdsTooltip solves the same problem the opposite way, with a document CAPTURE listener,
// because it never holds focus. Do not "align" the two.
// ⚠️ It does NOT teleport and must not be mounted inside a scroll container — that needs MdsTooltip's
// teleport-plus-exemption construction, not a change here.
export { default as MdsMenu } from './components/Menu/Menu.vue';
export type { MenuItem } from './components/Menu/Menu.vue';
// The take-the-page sequence MdsModal has owned since I10a, extracted in J4b for surfaces that need the
// same guarantee WITHOUT being dialogs — the sidebar's mobile drawer first, whose root wraps the primary
// navigation landmark at all three breakpoints and must not acquire `role="dialog"` at one of them.
// ⚠️ It is not a focus trap and does not need one: with the background inert there is nothing tabbable
// left outside the root. What it does supply is focus MANAGEMENT, and skipping that is a keyboard trap —
// once the stack is pushed, the opener is inert, so focus left on it is dropped to the body.
export { useInertBackground, type InertBackgroundOptions } from './components/Modal/useInertBackground';
export { default as MdsCheckbox } from './components/Checkbox/Checkbox.vue';
export { default as MdsSwitch } from './components/Switch/Switch.vue';
export { default as MdsSkeleton } from './components/Skeleton/Skeleton.vue';
export { default as MdsModal } from './components/Modal/Modal.vue';
/**
 * How many blocking dialogs currently own the page (Increment J1a).
 *
 * Promoted from `inert-stack.ts`'s test seam to the public surface because the app genuinely needs the
 * predicate: J1d's ⌘K palette must REFUSE to open over an existing modal. `inert-stack` would happily
 * stack it -- and `popModalRoot`'s contract then correctly declines to return focus to a dialog that is
 * no longer topmost, so the user lands on a page with an unfinished blocking task behind a palette.
 *
 * The package's `exports` map has no wildcard subpath, so `@meridian/design-system/components/Modal/
 * inert-stack` is not resolvable by a consumer and this re-export is the only way to reach it. (The map
 * does expose four other subpaths -- `./tokens`, `./tokens.css`, `./theme.css`, `./fonts.css` -- so this
 * is not "the only entry point", just the only JS one that carries components.)
 *
 * ⚠️ IT LAGS `open` BY ONE TICK, AND A SYNCHRONOUS CALLER MUST EXPECT THAT. The stack entry is pushed
 * inside `takePage()`'s `nextTick` callback, so a capture-phase key handler that fires in the same tick a
 * dialog's `open` flipped true reads 0 and would stack anyway. A global chord guarding on this should also
 * `await nextTick()` before deciding, or accept that the guard is best-effort against a dialog opened in
 * the very same frame.
 */
export { openModalCount } from './components/Modal/inert-stack';
export { default as MdsToast } from './components/Toast/Toast.vue';
export { default as MdsToastHost } from './components/Toast/ToastHost.vue';
export { default as MdsDataTable } from './components/DataTable/DataTable.vue';
export type { DataTableColumn } from './components/DataTable/DataTable.vue';
// The shared list-page filter surface (J1e). In the PACKAGE rather than the app tree on purpose: Storybook
// globs `packages/design-system/src/**` only, so an app-tree component gets no story and no `checkA11y`
// scan at all (DSR §4.6.1). FilterBar's whole reason for existing is a heading-order contract that
// only fails in the empty state, which is exactly the kind of thing a scan should be catching for us.
export { default as MdsFilterBar } from './components/FilterBar/FilterBar.vue';
export { default as MdsSearchField } from './components/SearchField/SearchField.vue';
// The per-resource navigation strip and the path trail (J2a), both in the package for the same coverage
// reason as FilterBar above. `TabNav` is NOT the ARIA tabs widget DSR §3.4 also specifies — its items are
// links that load a page, so it is a navigation landmark with `aria-current`, and dressing it in tab roles
// would strip every non-active destination out of the tab sequence. Read its docblock before "upgrading"
// it. The in-page tablist is `MdsTabs`, exported directly below since J4c — a sibling, not an upgrade.
export { default as MdsTabNav } from './components/TabNav/TabNav.vue';
export type { TabNavItem } from './components/TabNav/TabNav.vue';
// The ARIA-1.2 in-page tablist §3.4 has specified since Phase 0 (J4c), and `MdsTabNav`'s sibling rather
// than its upgrade — read that component's docblock before assuming the two can merge. Extracted from
// `ConfigPanel.vue`, the product's only in-page tablist, and the extraction bought SCRUTINY rather than
// reuse: an app-tree component gets no story and therefore no accessibility scan, which is how a tablist
// with no aria-controls, a panel sitting in the tab sequence, and a suppressed focus ring on that panel
// all survived every increment since it was written.
// ⚠️ aria-controls is on the SELECTED tab only, because only the selected panel is in the document — the
// palette's omitted-never-dangling rule, and axe downgrades a dangling one to `incomplete` rather than
// flagging it.
// ⛔ IT MUST NEVER BE RETROFITTED ONTO THE BUILDER'S PANE SWITCHER (DSR §3.4 carries the measurement):
// thirteen end-to-end locators walk the tab role on that page, four of them loops that click every match,
// and the panes are the switcher's siblings rather than its tabpanel children. That control stays a
// radiogroup.
export { default as MdsTabs } from './components/Tabs/Tabs.vue';
export type { TabItem } from './components/Tabs/Tabs.vue';
// The ARIA-1.2 combobox §3.4.1 and §4.5 have specified since Phase 0 (J4c). Extracted from
// `CommandPalette.vue`, which was the only implementation of this pattern in the product and was a LOGGED
// deviation for exactly that reason — the log said to wait for this increment, and its retirement deletes
// the entry rather than amending it.
// ⚠️ `aria-controls` and `aria-activedescendant` are absent whenever their target is, never dangling: axe
// resolves neither id, so no accessibility gate can see the lazy version.
// ⚠️ IT DOES NOT BIND ESCAPE, and that is a decision. Its consumer sits inside MdsModal, whose Escape is
// the dismissal; swallowing the first press would make closing the dialog take two. That makes it the third
// member of a family that must NOT be aligned — MdsTooltip captures on `document` because it never holds
// focus, MdsMenu binds its own root because it always does, and this binds neither.
// ⚠️ The listbox is IN FLOW, not an anchored popup. An anchored variant needs MdsTooltip's
// teleport-plus-exemption construction and is owed by its first consumer, in that consumer's own PR.
export { default as MdsCombobox } from './components/Combobox/Combobox.vue';
export type { ComboboxOption } from './components/Combobox/Combobox.vue';
export { default as MdsBreadcrumb } from './components/Breadcrumb/Breadcrumb.vue';
export type { BreadcrumbItem } from './components/Breadcrumb/Breadcrumb.vue';
export { default as MdsPagination } from './components/Pagination/Pagination.vue';
export { default as MdsTimeSeriesChart } from './components/TimeSeriesChart/TimeSeriesChart.vue';
export { default as MdsBarChart } from './components/BarChart/BarChart.vue';
export { default as MdsChartLegend } from './components/ChartLegend/ChartLegend.vue';
export {
    MAX_CHART_SERIES,
    type BarDatum,
    type ChartLegendItem,
    type ChartPoint,
    type ChartSeries,
} from './charts/types';
export { statusVariant, type BadgeVariant, type StatusDescriptor } from './components/Badge/status-variant';
export { type ToastType, type ToastItem } from './components/Toast/toast';
export { icons, type IconName } from './components/Icon/icons';

// The tenant brand-ramp engine's TypeScript twin (H23a1 / ADR-0014). Exported for ONE consumer: the
// admin branding picker's live preview (H23a2). PHP remains authoritative for anything that is stored —
// see brand-ramp.ts's header for why, and for the three implementation choices that keep the two engines
// byte-identical.
export {
    generateBrandRamp,
    brandRampSnap,
    brandRampByHeadroom,
    contrast as brandContrast,
    BRAND_RAMP_ROLES,
    BRAND_RAMP_PAIRINGS,
    BRAND_RAMP_ENGINE_VERSION,
    type BrandRampResult,
    type BrandRampTokens,
    type BrandRampRole,
    type BrandRampTheme,
    type BrandRampMeasurement,
} from './theme/brand-ramp';
