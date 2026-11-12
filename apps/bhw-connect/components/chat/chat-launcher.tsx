import Link from "next/link";
import type { DatasetScopeId } from "@/lib/ai/scope-id";
import type { GeoLevel } from "@/lib/filters/schema";

/** Public surfaces only navigate. No anonymous chat or cached answers remain. */
export function ChatLauncher({
  geoCode,
  geoLevel,
  dataset = "bhw",
}: {
  geoCode?: string;
  geoLevel?: GeoLevel;
  geoName?: string;
  dataset?: DatasetScopeId;
  starterQuestions?: string[];
  introLine?: string;
  inputPlaceholder?: string;
  methodologyHref?: string;
}) {
  const query = new URLSearchParams({ dataset });
  if (geoCode) query.set("geoCode", geoCode);
  if (geoLevel) query.set("geoLevel", geoLevel);
  return (
    <Link
      href={`/ask-the-data?${query}`}
      className="fixed bottom-24 right-4 z-30 rounded-full bg-accent px-4 py-3 text-sm font-medium text-accent-foreground shadow-lg hover:opacity-90"
    >
      Ask the Data · approved users
    </Link>
  );
}
