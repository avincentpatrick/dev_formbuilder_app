"use client";
import { researchSchema, RESEARCH_TOPICS } from "@/lib/policy-research/schema";
import type { PolicySources, PolicyRecord } from "@/lib/policy-analysis/schema";
export type PolicyOptions = {
  policies: PolicyRecord[];
  datasets: Array<{ tableName: string; title: string; grain: string }>;
};
export function PolicySourceControls({
  value,
  onChange,
  options,
  disabled,
}: {
  value: PolicySources;
  onChange: (value: PolicySources) => void;
  options: PolicyOptions;
  disabled: boolean;
}) {
  const change = (patch: Partial<PolicySources>) => onChange({ ...value, ...patch });
  const toggle = (values: string[], id: string) =>
    values.includes(id) ? values.filter((v) => v !== id) : [...values, id];
  const choices = options.policies.filter(
    (p) => value.historical || (!p.archived && p.current_revision),
  );
  return (
    <fieldset disabled={disabled} className="grid gap-3 rounded border border-border p-3 text-sm">
      <legend className="px-1 font-semibold">Sources for the next question</legend>
      <p className="text-muted">
        Earlier answers keep their original citations. Search-based answers are not an exhaustive
        policy review. Failed, processing and empty extractions are excluded.
      </p>
      <label>
        Policy scope
        <select
          className="block w-full rounded border p-2"
          value={value.mode}
          onChange={(e) =>
            change({
              mode: e.target.value as PolicySources["mode"],
              historical: false,
              versions: [],
              revisions: [],
            })
          }
        >
          <option value="active">Active processed library</option>
          <option value="selected">Selected versions or revisions</option>
        </select>
      </label>
      {value.mode === "active" ? (
        <label>
          Collection (optional)
          <input
            value={value.collection}
            onChange={(e) => change({ collection: e.target.value })}
            maxLength={100}
            className="block w-full rounded border p-2"
          />
        </label>
      ) : (
        <>
          <label>
            <input
              type="checkbox"
              checked={value.historical}
              onChange={(e) =>
                change({ historical: e.target.checked, versions: [], revisions: [] })
              }
            />{" "}
            Explicitly include archived versions and older extraction revisions
          </label>
          <div
            className="grid max-h-56 gap-2 overflow-y-auto rounded border p-2"
            role="group"
            aria-label="Selected policy revisions"
          >
            {choices.map((p) => (
              <label key={p.extraction_id} className="block">
                <input
                  type="checkbox"
                  checked={
                    value.revisions.includes(p.extraction_id) ||
                    (value.versions.includes(p.version_id) && p.current_revision)
                  }
                  onChange={(event) =>
                    change({
                      versions: value.versions.filter((v) => v !== p.version_id),
                      revisions: event.target.checked
                        ? [...new Set([...value.revisions, p.extraction_id])]
                        : value.revisions.filter((id) => id !== p.extraction_id),
                    })
                  }
                />{" "}
                {p.title} · version {p.version_id.slice(0, 8)} · revision {p.revision}
                {p.archived ? " · archived" : !p.current_revision ? " · older extraction" : ""} ·
                publication {p.publication_date ?? "not stated"}
              </label>
            ))}
            {!choices.length && (
              <p>No available policies. Upload or process a document in the library first.</p>
            )}
          </div>
          {options.policies.length >= 501 && (
            <p>
              The first 501 revisions are shown. Open a document from the library to select its
              version directly.
            </p>
          )}
        </>
      )}
      <label>
        Dataset selection
        <select
          className="block w-full rounded border p-2"
          value={value.datasetMode}
          onChange={(e) => change({ datasetMode: e.target.value as PolicySources["datasetMode"] })}
        >
          <option value="automatic">Automatic, across registered datasets</option>
          <option value="restricted">Only selected registered tables</option>
        </select>
      </label>
      {value.datasetMode === "restricted" && (
        <div
          className="grid max-h-48 gap-2 overflow-y-auto rounded border p-2"
          role="group"
          aria-label="Allowed dataset tables"
        >
          <p>Select none for policy-only analysis.</p>
          {options.datasets.map((d) => (
            <label key={d.tableName}>
              <input
                type="checkbox"
                checked={value.tables.includes(d.tableName)}
                onChange={() => change({ tables: toggle(value.tables, d.tableName) })}
              />{" "}
              {d.title}{" "}
              <span className="text-xs text-muted">
                ({d.tableName}; {d.grain})
              </span>
            </label>
          ))}
        </div>
      )}
      <label>
        <input
          type="checkbox"
          checked={value.online}
          onChange={(e) => change({ online: e.target.checked })}
        />{" "}
        Research official sources for this question or new run
      </label>
      {value.online && (
        <div className="grid gap-2">
          <p>
            Only these public search settings leave the app. Questions, uploaded passages and
            personal dataset records are never included in search requests. Enable again for each
            quick question; a full run freezes its choice.
          </p>
          <label>
            Official publisher
            <select
              className="block w-full rounded border p-2"
              value={value.research?.site ?? "doh.gov.ph"}
              onChange={(e) =>
                change({
                  research: researchSchema.parse({ ...value.research, site: e.target.value }),
                })
              }
            >
              {["doh.gov.ph", "pitahc.gov.ph", "officialgazette.gov.ph"].map((site) => (
                <option key={site}>{site}</option>
              ))}
            </select>
          </label>
          <label>
            Public research topic
            <select
              className="block w-full rounded border p-2"
              value={value.research?.topic ?? "barangay-health-workers"}
              onChange={(e) =>
                change({
                  research: researchSchema.parse({ ...value.research, topic: e.target.value }),
                })
              }
            >
              {Object.entries(RESEARCH_TOPICS).map(([key, title]) => (
                <option key={key} value={key}>
                  {title}
                </option>
              ))}
            </select>
          </label>
          <label>
            Public issuance number (optional; digits and hyphens)
            <input
              className="block w-full rounded border p-2"
              maxLength={24}
              value={value.research?.reference ?? ""}
              onChange={(e) => {
                if (/^[0-9-]*$/.test(e.target.value))
                  change({
                    research: researchSchema.parse({
                      ...value.research,
                      reference: e.target.value,
                    }),
                  });
              }}
            />
          </label>
          <p className="text-xs">
            Uses Brave Search when configured. Online excerpts are separate evidence; they do not
            change the library or establish legal supersession. Missing search configuration is
            reported as a gap.
          </p>
        </div>
      )}
      <p className="text-xs text-muted">
        Answers should distinguish policy requirements, figures printed in documents, dataset
        observations and analytical conclusions. OCR, missing fields and incompatible dates or
        definitions limit what can be concluded.
      </p>
    </fieldset>
  );
}
