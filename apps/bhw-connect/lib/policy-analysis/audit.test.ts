import { expect, it } from "vitest";
import { auditPolicyAnswer } from "./audit";
import { collectCitations, type Citation } from "@/lib/ai/citations";
const version = "11111111-1111-4111-8111-111111111111",
  extraction = "22222222-2222-4222-8222-222222222222";
const citation = (over: Partial<Citation> = {}): Citation => ({
  chunkId: 1,
  document: `upload:${extraction}`,
  documentTitle: "Referral policy Alpha",
  asOf: null,
  page: 1,
  heading: null,
  text: "Clinics must retain records for 20 days.",
  truncated: false,
  charStart: 0,
  charEnd: 39,
  label: "Alpha",
  versionId: version,
  extractionId: extraction,
  evidenceToken: `[[policy:${version}:${extraction}:1:1]]`,
  ...over,
});
it("binds an accepted policy quotation to immutable version/extraction/page identities", () => {
  const c = citation();
  const r = auditPolicyAnswer(
    `Policy requirement: “Clinics must retain records for 20 days.” ${c.evidenceToken}`,
    [c],
    [],
  );
  expect(r.rejected).toEqual([]);
  expect(r.text).toContain(`version ${version}; extraction ${extraction}; physical PDF page 1`);
});
it("rejects invented identities and same-page cross-document attribution", () => {
  const a = citation(),
    b = citation({
      chunkId: 2,
      document: "upload:other",
      documentTitle: "Referral policy Beta",
      versionId: "33333333-3333-4333-8333-333333333333",
      evidenceToken: `[[policy:33333333-3333-4333-8333-333333333333:${extraction}:2:1]]`,
      text: "A conflicting policy.",
    });
  for (const text of [
    `Referral policy Beta, page 1 requires records. ${a.evidenceToken}`,
    `Policy requirement: retain records. [[policy:invented:1:1]]`,
    `Page 1 requires records.`,
    `Policy requirement: “A conflicting policy.” ${a.evidenceToken}`,
  ])
    expect(auditPolicyAnswer(text, [a, b], []).text).toBe("");
});
it("rejects a fabricated quotation or documentary figure even when an offset contains that figure", () => {
  const c = citation({ charEnd: 99 });
  const payload = { results: [{ ...c, evidenceToken: c.evidenceToken }] };
  expect(
    auditPolicyAnswer(
      `Policy requirement: retain records for 99 days ${c.evidenceToken}`,
      [c],
      [payload],
    ).text,
  ).toBe("");
  expect(
    auditPolicyAnswer(
      `Policy requirement: “destroy all records immediately” ${c.evidenceToken}`,
      [c],
      [payload],
    ).text,
  ).toBe("");
});
it("keeps dataset measurements separate from documentary values and rejects unsupported totals", () => {
  const c = citation();
  const data = { rows: [{ clinics: 42 }] };
  expect(
    auditPolicyAnswer(
      `Dataset observation: 42 clinics were recorded. Analytical conclusion: this does not establish compliance.`,
      [c],
      [data],
    ).text,
  ).toContain("42 clinics");
  expect(
    auditPolicyAnswer(`Dataset observation: 97 clinics were recorded.`, [c], [data]).text,
  ).toBe("");
  expect(
    auditPolicyAnswer(
      `Policy requirement: retain records for 20 days ${c.evidenceToken}`,
      [c],
      [data],
    ).text,
  ).toContain("20 days");
});
it("upgrades truncated evidence when a full passage is read later", () => {
  const c = citation();
  const hit = { ...c, citation: c.label, text: "short", truncated: true };
  const full = { ...hit, text: c.text, truncated: false };
  expect(collectCitations([{ results: [hit] }, { results: [full] }])[0].text).toBe(c.text);
});

it("does not turn documentary figures into dataset observations by attaching a valid policy token", () => {
  const c = citation();
  expect(
    auditPolicyAnswer(`Dataset observation: 20 clinics were recorded ${c.evidenceToken}`, [c], [])
      .text,
  ).toBe("");
  expect(
    auditPolicyAnswer(
      `Documentary figure: 42 days ${c.evidenceToken}`,
      [c],
      [{ rows: [{ count: 42 }] }],
    ).text,
  ).toBe("");
});

it("withholds noncompliance inferred from missing fields and preserves explicit uncertainty", () => {
  const c = citation();
  expect(
    auditPolicyAnswer(
      `Analytical conclusion: the clinic is noncompliant because referral fields are missing ${c.evidenceToken}`,
      [c],
      [{ error: "Column not registered" }],
    ).text,
  ).toBe("");
  expect(
    auditPolicyAnswer(
      "Analytical conclusion: missing referral fields do not establish noncompliance.",
      [c],
      [{ error: "Column not registered" }],
    ).text,
  ).toContain("do not establish");
});

it("does not establish legal supersession merely from newer publication or same-page citations", () => {
  const a = citation(),
    b = citation({
      chunkId: 2,
      document: "upload:other",
      documentTitle: "Referral policy Beta",
      versionId: "33333333-3333-4333-8333-333333333333",
      evidenceToken: `[[policy:33333333-3333-4333-8333-333333333333:${extraction}:2:1]]`,
      text: "A conflicting policy.",
    });
  expect(
    auditPolicyAnswer(
      `Analytical conclusion: Referral policy Beta supersedes Referral policy Alpha because it is newer ${a.evidenceToken} ${b.evidenceToken}`,
      [a, b],
      [],
      [
        {
          title: a.documentTitle,
          document: a.document,
          version_id: a.versionId,
          supersedes_id: null,
        },
        {
          title: b.documentTitle,
          document: b.document,
          version_id: b.versionId,
          supersedes_id: null,
        },
      ],
    ).text,
  ).toBe("");
});

it("checks source dates against the cited identity rather than another policy's metadata", () => {
  const c = citation({ asOf: "2024-01-15" });
  expect(
    auditPolicyAnswer(`Source metadata: published 2024-01-15 ${c.evidenceToken}`, [c], []).text,
  ).toContain("2024-01-15");
  expect(
    auditPolicyAnswer(`Source metadata: published 2024-02-15 ${c.evidenceToken}`, [c], []).text,
  ).toBe("");
});

it("does not use generic percentage/year allowances as evidence for unsupported dataset counts", () => {
  expect(auditPolicyAnswer("Dataset observation: 100 workers were counted.", [], []).text).toBe("");
  expect(
    auditPolicyAnswer("Dataset observation: 100 workers were counted.", [], [{ matchingRows: 100 }])
      .text,
  ).toContain("100 workers");
});

it("retains dates from actual dataset fields and rejects a fabricated reporting date", () => {
  const payload = { rows: [{ reporting_date: "2023-03-15" }] };
  expect(
    auditPolicyAnswer("Dataset observation: reporting date 2023-03-15.", [], [payload]).text,
  ).toContain("2023-03-15");
  expect(
    auditPolicyAnswer("Dataset observation: reporting date 2023-04-15.", [], [payload]).text,
  ).toBe("");
});
