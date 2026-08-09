export { default as MdsButton } from './components/Button/Button.vue';
export { default as MdsIconButton } from './components/IconButton/IconButton.vue';
export { default as MdsFormField } from './components/FormField/FormField.vue';
export { default as MdsTextInput } from './components/TextInput/TextInput.vue';
export { default as MdsTextarea } from './components/Textarea/Textarea.vue';
export { default as MdsSelect } from './components/Select/Select.vue';
export { default as MdsNumberInput } from './components/NumberInput/NumberInput.vue';
export { default as MdsPasswordInput } from './components/PasswordInput/PasswordInput.vue';
export { default as MdsIcon } from './components/Icon/Icon.vue';
export { default as MdsCard } from './components/Card/Card.vue';
export { default as MdsStatTile } from './components/StatTile/StatTile.vue';
export { default as MdsEmptyState } from './components/EmptyState/EmptyState.vue';
export { default as MdsSegmentedControl } from './components/SegmentedControl/SegmentedControl.vue';
export { default as MdsSpinner } from './components/Spinner/Spinner.vue';
export { default as MdsBadge } from './components/Badge/Badge.vue';
export { default as MdsBanner } from './components/Banner/Banner.vue';
export { type BannerTone } from './components/Banner/Banner.vue';
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
