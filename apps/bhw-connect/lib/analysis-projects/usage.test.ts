import { describe, it, expect } from "vitest";
import { analysisUsage, observeProviderUse, summariseUsage, type ProviderUse } from "./usage";
describe("analysis usage and cost estimates", () => {
  it("keeps concurrent projects isolated and unknown costs explicit", async () => {
    const a: ProviderUse[] = [],
      b: ProviderUse[] = [];
    await Promise.all([
      analysisUsage.run(a, async () => {
        await Promise.resolve();
        observeProviderUse("gemini", {
          content: "a",
          toolCalls: [],
          model: "m",
          usage: { inputTokens: 100, outputTokens: 50 },
        });
      }),
      analysisUsage.run(b, async () => {
        observeProviderUse("groq", { content: "b", toolCalls: [] });
      }),
    ]);
    expect(a).toHaveLength(1);
    expect(b).toHaveLength(1);
    expect(a[0].provider).toBe("gemini");
    expect(summariseUsage(a).estimatedCostUsd).toBeNull();
    expect(
      summariseUsage(a, JSON.stringify({ "gemini/m": { inputPerMillion: 1, outputPerMillion: 2 } }))
        .estimatedCostUsd,
    ).toBeCloseTo(0.0002);
    expect(summariseUsage(b, "invalid").estimatedCostUsd).toBeNull();
    expect(summariseUsage([]).estimatedCostUsd).toBeNull();
  });
});
