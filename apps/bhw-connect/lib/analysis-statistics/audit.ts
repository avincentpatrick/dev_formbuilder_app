import type { StatisticalResult } from "./types";
// Numeric computation claims bind to named fields, never arbitrary payload number membership.
export function auditComputedNarrative(text: string, results: StatisticalResult[]) {
  const rejectedSentences: string[] = [],
    kept: string[] = [];
  const lookup = new Map(results.map((r) => [r.id, r]));
  for (const sentence of text.split(/(?<=[.!?])\s+/).filter(Boolean)) {
    let valid = true;
    const without = sentence.replace(/\[\[([a-f0-9]{16}):([A-Za-z0-9_]+)\]\]/g, (_, id, key) => {
      if (!lookup.get(id)?.claims[key]) valid = false;
      return "STATISTIC";
    });
    if (/\d|\[\[|\]\]/.test(without)) valid = false;
    if (!valid) {
      rejectedSentences.push(sentence);
      continue;
    }
    kept.push(
      sentence.replace(
        /\[\[([a-f0-9]{16}):([A-Za-z0-9_]+)\]\]/g,
        (_, id, key) => lookup.get(id)!.claims[key].display,
      ),
    );
  }
  return { text: kept.join(" "), rejectedSentences };
}
