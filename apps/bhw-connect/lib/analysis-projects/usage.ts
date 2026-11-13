import "server-only";
import { AsyncLocalStorage } from "node:async_hooks";
import type { ProviderCompletion } from "@/lib/ai/providers/types";
export type ProviderUse = {
  provider: string;
  model: string | null;
  inputTokens: number | null;
  outputTokens: number | null;
};
export const analysisUsage = new AsyncLocalStorage<ProviderUse[]>();
export function observeProviderUse(provider: string, completion: ProviderCompletion) {
  analysisUsage.getStore()?.push({
    provider,
    model: completion.model ?? null,
    inputTokens: completion.usage?.inputTokens ?? null,
    outputTokens: completion.usage?.outputTokens ?? null,
  });
}
export function summariseUsage(calls: ProviderUse[], pricesText = process.env.BHW_AI_PRICES_JSON) {
  let prices: Record<string, { inputPerMillion: number; outputPerMillion: number }> = {};
  try {
    prices = JSON.parse(pricesText ?? "{}");
  } catch {
    /* Unconfigured prices are unknown, never zero. */
  }
  let cost = 0;
  let known = calls.length > 0;
  for (const c of calls) {
    const p = prices?.[`${c.provider}/${c.model}`];
    if (
      !p ||
      c.inputTokens === null ||
      c.outputTokens === null ||
      !Number.isFinite(p.inputPerMillion) ||
      !Number.isFinite(p.outputPerMillion) ||
      p.inputPerMillion < 0 ||
      p.outputPerMillion < 0
    ) {
      known = false;
      continue;
    }
    cost += (c.inputTokens * p.inputPerMillion + c.outputTokens * p.outputPerMillion) / 1_000_000;
  }
  return {
    requests: calls.length,
    calls,
    estimatedCostUsd: known ? cost : null,
    note: known
      ? "Estimate using configured model prices; excludes external service charges."
      : "Cost unavailable: provider usage or matching model prices are missing. This does not mean usage is free.",
  };
}
