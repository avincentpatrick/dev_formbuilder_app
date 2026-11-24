"use client";
import { useEffect, useState } from "react";
import type { CatalogSelection } from "@/lib/catalog/types";
import { presenceSchema, type PresenceInput } from "@/lib/facilities/presence";
import { headlineQuery } from "@/lib/facilities/headline";
import { withBasePath } from "@/lib/base-path";
/** Fetch union presence only when the exact-area disclosure needs it. */
export function useAreaPresence(
  initial: PresenceInput | null,
  selection: CatalogSelection,
  types: string[],
  enabled: boolean,
) {
  const scope = headlineQuery(selection).toString();
  const typeKey = JSON.stringify([...types].sort());
  const [attempt, setAttempt] = useState(0);
  const key = `${scope}|${typeKey}|${attempt}`;
  const [loaded, setLoaded] = useState<{ key: string; data: PresenceInput | null }>({
    key: "",
    data: null,
  });
  const accepted = types.length === 0 && initial !== null && attempt === 0;
  useEffect(() => {
    if (!enabled || accepted) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const query = new URLSearchParams(scope);
        for (const type of JSON.parse(typeKey) as string[]) query.append("type", type);
        const response = await fetch(withBasePath(`/api/facilities/presence?${query}`), {
          signal: controller.signal,
        });
        if (!response.ok) throw Error("Unavailable");
        const parsed = presenceSchema.safeParse(await response.json());
        if (
          !parsed.success ||
          JSON.stringify([...parsed.data.selectedTypes].sort()) !== typeKey ||
          (initial &&
            (parsed.data.code !== initial.code ||
              parsed.data.level !== initial.level ||
              parsed.data.rows.length !== initial.rows.length ||
              parsed.data.rows.some(
                (p) =>
                  !initial.rows.some(
                    (b) =>
                      b.code === p.code &&
                      b.level === p.level &&
                      b.barangays === p.barangays &&
                      p.present <= b.present,
                  ),
              )))
        )
          throw Error("Invalid summary");
        if (!controller.signal.aborted) setLoaded({ key, data: parsed.data });
      } catch {
        if (!controller.signal.aborted) setLoaded({ key, data: null });
      }
    }, 200);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [scope, typeKey, key, enabled, accepted, initial]);
  return {
    data: accepted ? initial : loaded.key === key ? loaded.data : null,
    pending: enabled && !accepted && loaded.key !== key,
    retry: () => setAttempt((v) => v + 1),
  };
}
