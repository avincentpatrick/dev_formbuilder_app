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
    const frozen = (body.contents ?? [])
      .flatMap((c) => c.parts ?? [])
      .flatMap((p) => {
        if (!p.text) return [];
        try {
          const data = JSON.parse(p.text);
          return data.untrustedPolicySection?.results ?? [];
        } catch {
          return [];
        }
      });
    if (frozen.length) results.push(...frozen);
    const policyToken = results.find((r) => r.evidenceToken)?.evidenceToken;
    const combined = process.env.BHW_POLICY_E2E_COMBINED === "1";
    const hasDictionary = responses.some((p) => p.functionResponse.name === "listDatasets");
    const query = responses.find((p) => p.functionResponse.name === "queryDataset")
      ?.functionResponse.response;
    if (combined && results.length && !hasDictionary)
      return Response.json({
        candidates: [
          {
            content: {
              parts: [{ functionCall: { name: "listDatasets", args: { table: "fact_bhw_raw" } } }],
            },
          },
        ],
      });
    if (combined && results.length && !query)
      return Response.json({
        candidates: [
          {
            content: {
              parts: [
                {
                  functionCall: {
                    name: "queryDataset",
                    args: { table: "fact_bhw_raw", mode: "count" },
                  },
                },
              ],
            },
          },
        ],
      });
    if (frozen.length) {
      const hit = frozen[0];
      const phrase = hit.text.includes("Original signed endorsement")
        ? "Original signed endorsement must accompany each exception."
        : "Every clinic must retain a signed referral record.";
      const findings = hit.text.includes(phrase)
        ? [
            {
              kind: "policy_requirement",
              topic: "record retention",
              text: `${phrase} ${hit.evidenceToken}`,
              quote: phrase,
              comparisonValue: phrase,
            },
          ]
        : [];
      if (typeof query?.matchingRows === "number")
        findings.push({
          kind: "dataset_observation",
          topic: "available dataset",
          text: `${query.matchingRows} BHW records were counted. ${hit.evidenceToken}`,
          quote: phrase,
          comparisonValue: "",
        });
      return Response.json({
        candidates: [
          {
            content: {
              parts: [
                {
                  text: JSON.stringify({
                    relevant: findings.length > 0,
                    findings,
                    limitations: [],
                  }),
                },
              ],
            },
          },
        ],
      });
    }
    const parts = results.length
      ? [
          {
            text:
              (combined
                ? "Policy requirement: The policy requires a signed referral record."
                : "The policy requires a signed referral record.") +
              (policyToken ? ` ${policyToken}` : "") +
              (combined
                ? typeof query?.matchingRows === "number"
                  ? `. Dataset observation: ${query.matchingRows} BHW records were counted. Analytical conclusion: record counts alone do not establish compliance.`
                  : ". Dataset observation: the selected data is unavailable."
                : ""),
          },
        ]
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
