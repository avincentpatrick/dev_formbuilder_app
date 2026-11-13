// Test process preload only: deterministic provider transport, no production imports.
const original = globalThis.fetch;
globalThis.fetch = async (input, init) => {
  const url = new URL(typeof input === "string" ? input : (input.url ?? input.href));
  if (url.hostname === "generativelanguage.googleapis.com") {
    const body = JSON.parse(init.body);
    const responses = (body.contents ?? [])
      .flatMap((c) => c.parts ?? [])
      .filter((p) => p.functionResponse);
    const results = responses.flatMap((p) => p.functionResponse.response.results ?? []);
    const parts = results.length
      ? [{ text: "The policy requires a signed referral record." }]
      : [{ functionCall: { name: "searchDocuments", args: { query: "referral" } } }];
    return Response.json({
      candidates: [{ content: { parts } }],
      usageMetadata: { totalTokenCount: 0 },
    });
  }
  if (!["127.0.0.1", "localhost"].includes(url.hostname))
    throw new Error("External network disabled by synthetic provider fixture");
  return original(input, init);
};
