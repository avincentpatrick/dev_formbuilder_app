import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { withBasePath } from "@/lib/base-path";

export function BhwSearchForm({
  query = "",
  compact = false,
  mapState,
}: {
  query?: string;
  compact?: boolean;
  mapState?: string | string[];
}) {
  const snapshot = restoredBhwMapHref(mapState)?.split("?")[1];
  return (
    <form action={withBasePath("/bhw/search")} method="get" role="search" className="space-y-2">
      {snapshot && <input type="hidden" name="mapState" value={snapshot} />}
      <label htmlFor="bhw-search" className="block font-medium">
        Search BHW places and indicators
      </label>
      <div className="flex gap-2">
        <input
          id="bhw-search"
          name="q"
          type="search"
          defaultValue={query}
          maxLength={100}
          placeholder="Cebu accreditation"
          aria-describedby="bhw-search-help"
          className="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-3"
        />
        <button className="rounded-md bg-accent px-4 py-3 font-medium text-white">Search</button>
      </div>
      <p id="bhw-search-help" className="text-sm text-muted">
        {compact
          ? "Place, PSGC code, or indicator."
          : "Try a place name or PSGC code, “headcount”, “Cebu accreditation”, or “Compare Cebu and Bohol accreditation”. Choose among matching places and measures before opening a figure."}
      </p>
    </form>
  );
}
