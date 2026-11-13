"use client";
import { useEffect, useState, useCallback } from "react";
import Link from "next/link";
import { withBasePath } from "@/lib/base-path";
import type { ProjectSource, SourceMetadata } from "@/lib/analysis-sources/types";
export function SourcePanel({ projectId, canEdit }: { projectId: string; canEdit: boolean }) {
  const [sources, setSources] = useState<ProjectSource[]>([]),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  const refresh = useCallback(async () => {
    const r = await fetch(withBasePath(`/api/analysis/sources?projectId=${projectId}`), {
      cache: "no-store",
    });
    if (!r.ok) {
      setSources([]);
      throw new Error("Project sources are unavailable");
    }
    setSources(await r.json());
  }, [projectId]);
  useEffect(() => {
    let live = true;
    const load = () =>
      void refresh().catch((e) => {
        if (live) setError(e.message);
      });
    load();
    const timer = setInterval(load, 4000);
    return () => {
      live = false;
      clearInterval(timer);
    };
  }, [refresh]);
  async function change(sourceId: string, operation: string, metadata?: SourceMetadata) {
    setError("");
    const r = await fetch(withBasePath("/api/analysis/sources"), {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ projectId, sourceId, operation, metadata }),
    });
    if (!r.ok) {
      setError("Source could not be changed. Check access and status.");
      return;
    }
    await refresh();
  }
  return (
    <section className="flex flex-col gap-3 rounded border border-border p-4">
      <h2 className="font-semibold">Project sources</h2>
      <p className="text-sm text-muted">
        Attachments remain private to this project and its permitted members. Originals are
        preserved; preparation notes and source definitions accompany the analysis. Online evidence
        retrieved by the assistant also appears here.
      </p>
      {error && <p role="alert">{error}</p>}
      {canEdit && (
        <>
          <label htmlFor="analysis-files">Attach files</label>
          <input
            id="analysis-files"
            type="file"
            multiple
            disabled={busy}
            accept=".csv,.xlsx,.xls,.pdf,.doc,.docx,.ppt,.pptx,.geojson,.json,.zip"
            onChange={async (e) => {
              const files = Array.from(e.target.files ?? []);
              setBusy(true);
              setError("");
              try {
                for (const file of files) {
                  const r = await fetch(
                    withBasePath(`/api/analysis/sources?projectId=${projectId}`),
                    {
                      method: "POST",
                      headers: {
                        "Content-Type": "application/octet-stream",
                        "X-File-Name": encodeURIComponent(file.name),
                      },
                      body: file,
                    },
                  );
                  if (!r.ok) {
                    const data = await r.json();
                    throw new Error(data.error ?? "Upload failed");
                  }
                }
                await refresh();
              } catch (error) {
                setError((error as Error).message);
              } finally {
                setBusy(false);
                e.target.value = "";
              }
            }}
          />
          <p className="text-xs text-muted">
            CSV, Excel, PDF, Word, PowerPoint, GeoJSON or one zipped shapefile set. Current
            processing limit: 25 MiB per file. Legacy Office conversion requires the configured
            converter; unreadable scans are labelled clearly.
          </p>
        </>
      )}
      {busy && <p role="status">Saving original files…</p>}
      {sources.map((s) => (
        <SourceCard key={s.id} source={s} projectId={projectId} canEdit={canEdit} change={change} />
      ))}
    </section>
  );
}
function SourceCard({
  source,
  projectId,
  canEdit,
  change,
}: {
  source: ProjectSource;
  projectId: string;
  canEdit: boolean;
  change: (id: string, operation: string, metadata?: SourceMetadata) => Promise<void>;
}) {
  const [metadata, setMetadata] = useState<SourceMetadata>(source.metadata);
  const fields: [keyof SourceMetadata, string][] = [
    ["reportingPeriod", "Reporting period"],
    ["grain", "One row represents"],
    ["unit", "Measurement units"],
    ["denominator", "Denominator / reference population (for rates or percentages)"],
    ["geographyVersion", "Geographic boundary version"],
    ["joinKeys", "Join columns (comma separated)"],
    ["crs", "CRS override, if needed"],
  ];
  return (
    <details className="rounded border border-border p-3">
      <summary className="break-words">
        {source.name} · {source.status}
      </summary>
      <p className="my-2 text-sm">
        {source.kind === "web" ? "Online source" : "Attachment"} · {source.bytes.toLocaleString()}{" "}
        bytes
      </p>
      {source.error && <p className="text-sm">{source.error}</p>}
      {source.url && (
        <a
          href={source.url}
          target="_blank"
          rel="noreferrer"
          className="break-all text-sm underline"
        >
          Original online location
        </a>
      )}
      <ul className="my-2 list-inside list-disc text-sm">
        {source.profile?.warnings?.map((warning, i) => (
          <li key={i}>{warning}</li>
        ))}
      </ul>
      {source.preparations?.map((p, i) => (
        <p key={i} className="my-1 text-sm">
          {p.action}: {p.detail}
        </p>
      ))}
      <Link
        href={`/ask-the-data/projects/${projectId}/sources/${source.id}`}
        className="mr-3 text-sm underline"
      >
        Review extracted evidence
      </Link>
      <a
        href={withBasePath(`/api/analysis/sources/${source.id}?projectId=${projectId}`)}
        className="text-sm underline"
      >
        Download original
      </a>
      {canEdit && (
        <>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void change(source.id, "metadata", metadata);
            }}
            className="my-3 flex flex-col gap-2"
          >
            <p className="text-sm">
              Document these definitions when combining figures. Unknown definitions are not
              guessed. Changing the CRS queues a fresh extraction.
            </p>
            {fields.map(([key, label]) => (
              <label key={key} className="flex flex-col gap-1 text-sm">
                {label}
                <input
                  value={metadata[key] ?? ""}
                  onChange={(e) =>
                    setMetadata((p) => ({ ...p, [key]: e.target.value || undefined }))
                  }
                  maxLength={key === "crs" ? 3000 : 200}
                  className="min-w-0 rounded border border-border bg-background p-2"
                />
              </label>
            ))}
            <button className="rounded border border-border p-2">Save definitions</button>
          </form>
          {["ready", "blocked", "failed"].includes(source.status) && (
            <button
              onClick={() => void change(source.id, "retry")}
              className="mr-3 text-sm underline"
            >
              Retry extraction
            </button>
          )}
          <button
            className="text-sm underline"
            onClick={() => {
              if (window.confirm("Delete this source and invalidate saved results that used it?"))
                void change(source.id, "delete");
            }}
          >
            Delete source
          </button>
        </>
      )}
    </details>
  );
}
