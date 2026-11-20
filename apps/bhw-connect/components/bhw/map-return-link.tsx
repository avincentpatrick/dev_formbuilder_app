import Link from "next/link";

/** href is rebuilt by the dataset snapshot validator, never an arbitrary redirect URL. */
export function BhwMapReturnLink({
  href,
  scopeNote,
  datasetName = "BHW",
}: {
  href: string | null;
  scopeNote: string;
  datasetName?: string;
}) {
  return href ? (
    <aside className="rounded border border-border p-3 text-sm">
      <Link href={href} className="underline">
        Return to previous {datasetName} map
      </Link>
      <p className="mt-1 text-muted">
        Restores the map you came from, including its indicator, area, selected place and supported
        display settings. {scopeNote}
      </p>
    </aside>
  ) : (
    <p role="status">
      The previous map settings are invalid or unsupported. Open a new map from the dataset details.
    </p>
  );
}
