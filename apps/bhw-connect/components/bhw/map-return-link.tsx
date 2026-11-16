import Link from "next/link";

/** href is rebuilt by restoredBhwMapHref, never taken directly from a URL parameter. */
export function BhwMapReturnLink({ href, scopeNote }: { href: string | null; scopeNote: string }) {
  return href ? (
    <aside className="rounded border border-border p-3 text-sm">
      <Link href={href} className="underline">
        Return to previous BHW map
      </Link>
      <p className="mt-1 text-muted">
        Restores the map you came from, including its indicator, area, selected place, view, filter
        and color groups. {scopeNote}
      </p>
    </aside>
  ) : (
    <p role="status">
      The previous map settings are invalid or unsupported. Open a new map from the dataset details.
    </p>
  );
}
