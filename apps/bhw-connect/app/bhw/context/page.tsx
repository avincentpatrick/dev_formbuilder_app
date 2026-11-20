import Link from "next/link";
import { getGeoByCode } from "@/lib/db/geo";
import { parseContext } from "@/lib/bhw/context";
import { ContextWorkspace } from "@/components/bhw/context-workspace";
export const metadata = { title: "BHW workforce and context" };
export default async function ContextPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const raw = await searchParams;
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(raw))
    for (const item of Array.isArray(v) ? v : v === undefined ? [] : [v]) params.append(k, item);
  const choice = parseContext(params);
  const geo = choice ? await getGeoByCode(choice.map.code) : null;
  if (!choice || !geo || geo.geoLevel !== choice.map.level)
    return (
      <div className="mx-auto max-w-4xl space-y-4 p-4">
        <h1 className="text-2xl font-semibold">Could not verify this context view</h1>
        <p role="alert">
          Use a supported geographic scope and context. No national fallback was applied.
        </p>
        <Link href="/bhw" className="underline">
          Open BHW dashboard
        </Link>
      </div>
    );
  return <ContextWorkspace initial={choice} />;
}
