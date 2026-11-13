import { auditNarrative, extractNumbers } from "@/lib/ai/audit";
import { extractPageReferences, type Citation } from "@/lib/ai/citations";
const TOKEN = /\[\[policy:[^\]]*\]\]/g;
const normalize = (s: string) => s.replace(/\s+/g, " ").trim().toLocaleLowerCase();
const numericValues = (value: unknown): number[] => {
  if (typeof value === "number" && Number.isFinite(value)) return [value];
  if (Array.isArray(value)) return value.flatMap(numericValues);
  if (value && typeof value === "object") return Object.values(value).flatMap(numericValues);
  return [];
};
const structuredDates = (value: unknown): string[] => {
  if (typeof value === "string")
    return /^\d{4}-\d{2}-\d{2}(?:T.*)?$/.test(value) ? [value.slice(0, 10)] : [];
  if (Array.isArray(value)) return value.flatMap(structuredDates);
  if (value && typeof value === "object") return Object.values(value).flatMap(structuredDates);
  return [];
};
/** Exact identity/quotation/numeric checks, not a claim of semantic or legal verification. */
export function auditPolicyAnswer(
  raw: string,
  citations: Citation[],
  payloads: unknown[],
  identities: Array<{
    title: string;
    document: string;
    version_id?: string;
    supersedes_id?: string | null;
    publication_date?: string | null;
    effective_date?: string | null;
  }> = [],
) {
  const policy = citations.filter((c) => c.evidenceToken && c.versionId && c.extractionId);
  const known = new Map(
    policy
      .filter(
        (c) =>
          c.evidenceToken === `[[policy:${c.versionId}:${c.extractionId}:${c.chunkId}:${c.page}]]`,
      )
      .map((c) => [c.evidenceToken!, c]),
  );
  const datasetPayloads = payloads.filter((p) => {
    if (!p || typeof p !== "object") return true;
    const r = p as { policies?: unknown; results?: Array<{ evidenceToken?: string }> };
    return !r.policies && !r.results?.some((hit) => hit.evidenceToken);
  });
  let category: "document" | "dataset" | "mixed" = "mixed";
  const rejected: string[] = [];
  const kept: string[] = [];
  for (const sentence of raw
    .split(/(?<=[.!?]|\]\])\s+(?!\s*\[\[policy:)/)
    .map((s) => s.trim())
    .filter(Boolean)) {
    if (/dataset observations?/i.test(sentence)) category = "dataset";
    if (/policy requirements?|documentary figures?/i.test(sentence)) category = "document";
    if (/analytical conclusions?/i.test(sentence)) category = "mixed";
    const tokens = sentence.match(TOKEN) ?? [];
    const selected = tokens.map((t) => known.get(t));
    const pages = extractPageReferences(sentence);
    const invalid = tokens.some((_, i) => !selected[i]);
    const matched = selected.filter((c): c is Citation => Boolean(c));
    const wrongPage = pages.some(
      (p) =>
        !matched.some(
          (c) => p >= c.page && p <= (c.pageRange ? Number(c.pageRange.split("-")[1]) : c.page),
        ),
    );
    const names = [
      ...identities,
      ...policy.map((c) => ({ title: c.documentTitle, document: c.document })),
    ];
    const wrongTitle = names.some(
      (c) =>
        normalize(sentence).includes(normalize(c.title)) &&
        !matched.some((m) => m.document === c.document),
    );
    const quotes = [...sentence.matchAll(/[“"]([^”"]{8,})[”"]/g)].map((m) => normalize(m[1]));
    const badQuote =
      matched.length > 0 && quotes.some((q) => !matched.some((c) => normalize(c.text).includes(q)));
    const missing =
      !tokens.length &&
      (pages.length > 0 ||
        /\b(policy requirement|documentary figure|shall|must|requires?|required|mandates?|prohibits?)\b/i.test(
          sentence,
        ));
    const regulatory =
      /\b(non[- ]?compliant|violat(?:es?|ion)|fails? to comply|(?:is|are|was|were) compliant)\b/i.test(
        sentence,
      );
    const limitation =
      /\b(cannot|unclear|unknown|insufficient|not establish|no basis|do not|does not)\b/i.test(
        sentence,
      );
    const observed = datasetPayloads.some((p) => {
      if (!p || typeof p !== "object") return false;
      const data = p as { error?: unknown; rows?: unknown[]; matchingRows?: number };
      return (
        !data.error &&
        (Boolean(data.rows?.length) ||
          (typeof data.matchingRows === "number" && data.matchingRows > 0))
      );
    });
    const supersession = /\b(supersedes?|repeals?|overrides?|replaces?)\b/i;
    const declaredLink = matched.some((c) =>
      identities.some((r) => r.version_id === c.versionId && r.supersedes_id),
    );
    const quotedLink = quotes.some((q) => supersession.test(q));
    const unsupportedSupersession =
      supersession.test(sentence) && !limitation && !declaredLink && !quotedLink;
    const policyDates = matched
      .flatMap((c) => [
        c.asOf,
        ...(c.text.match(/\b\d{4}-\d{2}-\d{2}\b/g) ?? []),
        ...identities
          .filter((r) => r.document === c.document)
          .flatMap((r) => [r.publication_date, r.effective_date]),
      ])
      .filter((d): d is string => Boolean(d));
    const dataDates = structuredDates(datasetPayloads);
    const dates =
      category === "document"
        ? policyDates
        : category === "dataset"
          ? dataDates
          : [...policyDates, ...dataDates];
    const claimedDates = sentence.match(/\b\d{4}-\d{2}-\d{2}\b/g) ?? [];
    const badDate = claimedDates.some((d) => !dates.includes(d));
    const unsupportedRegulatoryClaim =
      regulatory &&
      !limitation &&
      category !== "document" &&
      (!matched.length ||
        !observed ||
        /\b(missing|absent|unavailable|not recorded)\b/i.test(sentence));
    if (
      (sentence.includes("[[policy:") && !tokens.length) ||
      unsupportedRegulatoryClaim ||
      unsupportedSupersession ||
      badDate ||
      invalid ||
      wrongPage ||
      wrongTitle ||
      badQuote ||
      missing
    ) {
      rejected.push(sentence);
      continue;
    }
    // Remove identity tokens during numeric checks: UUID digits and offsets never justify claims.
    const plain = sentence.replace(TOKEN, "");
    const dateValues =
      claimedDates.length || /\b(publication|published|effective|as of)\b/i.test(sentence)
        ? dates.flatMap((d) => [...extractNumbers(d), ...extractNumbers(d.replaceAll("-", " "))])
        : [];
    const numbers = category === "dataset" ? [] : matched.flatMap((c) => extractNumbers(c.text));
    const numericText = plain.replace(
      /\b(?:slides?|pages?|pp?\.?)\s*\d{1,4}(?:\s*(?:[–—-]|to)\s*\d{1,4})?\b/gi,
      "",
    );
    const numericEvidence = [
      ...(category === "document" ? [] : datasetPayloads),
      { documentaryValues: numbers, verifiedDateValues: dateValues },
    ];
    const numeric = auditNarrative(numericText, numericEvidence);
    const actualValues = numericValues(numericEvidence);
    const strictlyTraceable = extractNumbers(numericText).every((n) =>
      actualValues.some((a) => Math.abs(a - n) < 1e-9 || Math.round(a) === Math.round(n)),
    );
    if (plain.trim() && (!numeric.text || !strictlyTraceable)) {
      rejected.push(sentence);
      continue;
    }
    // Validated source labels are inserted after the numeric check, never authored by the model.
    kept.push(
      sentence.replace(TOKEN, (t) => {
        const c = known.get(t)!;
        return `(${c.documentTitle}; version ${c.versionId}; extraction ${c.extractionId}; physical PDF page ${c.page})`;
      }),
    );
  }
  return { text: kept.join(" "), rejected };
}
