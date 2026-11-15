import { describe, it, expect } from "vitest";
import { extractOnline } from "./extract";
describe("untrusted online text extraction", () => {
  it("keeps reported publication/effective dates separate from retrieval, removes executable markup and never follows links", async () => {
    const raw =
      '<html><head><title>Synthetic issuance</title><meta property="article:published_time" content="2025-01-02T10:00:00Z"></head><body><script>fetch("https://evil.example")</script><main>Effective date: 2025-02-01. Clinics must retain referrals. <a href="https://evil.example">untrusted link</a></main></body></html>';
    const result = await extractOnline(Buffer.from(raw), "text/html", AbortSignal.timeout(1000));
    expect(result).toMatchObject({
      title: "Synthetic issuance",
      publicationDate: "2025-01-02",
      effectiveDate: "2025-02-01",
    });
    expect(result.pages[0].text).not.toContain("fetch(");
    expect(result.pages[0].text).toContain("untrusted link");
  });
  it("does not infer an effective date from publication or a retrieval timestamp", async () => {
    const result = await extractOnline(
      Buffer.from(
        '<html><head><meta name="date" content="2025-01-02"></head><body>Policy text without an effective date.</body></html>',
      ),
      "text/html",
      AbortSignal.timeout(1000),
    );
    expect(result.publicationDate).toBe("2025-01-02");
    expect(result.effectiveDate).toBeNull();
  });
  it("rejects executable/binary content rather than presenting it as policy text", async () => {
    await expect(
      extractOnline(Buffer.from("binary"), "application/octet-stream", AbortSignal.timeout(1000)),
    ).rejects.toThrow();
  });
});
