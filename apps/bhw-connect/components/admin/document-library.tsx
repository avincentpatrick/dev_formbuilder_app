"use client";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { withBasePath } from "@/lib/base-path";
import type { Catalog, Detail } from "@/lib/documents/library";

async function change(body: FormData | Record<string, unknown>) {
  const form = body instanceof FormData;
  const response = await fetch(withBasePath("/api/admin/documents"), {
    method: "POST",
    headers: form ? undefined : { "Content-Type": "application/json" },
    body: form ? body : JSON.stringify(body),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error ?? "Document request failed");
  return data;
}
function Fields({ detail }: { detail?: Detail }) {
  return (
    <>
      <label>
        Title
        <input
          name="title"
          required
          maxLength={300}
          defaultValue={detail?.title}
          className="block w-full rounded border p-2"
        />
      </label>
      <label>
        Issuing agency
        <input
          name="issuer"
          maxLength={200}
          defaultValue={detail?.issuer ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
      <label>
        Document type
        <input
          name="type"
          maxLength={100}
          placeholder="Policy, circular, order…"
          defaultValue={detail?.document_type ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
      <label>
        Issuance number
        <input
          name="number"
          maxLength={100}
          defaultValue={detail?.issuance_number ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
      <label>
        Tags (comma separated)
        <input
          name="tags"
          defaultValue={detail?.tags.join(", ") ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
      <label>
        Collection
        <input
          name="collection"
          maxLength={100}
          defaultValue={detail?.collection_key ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
    </>
  );
}
function Dates({ detail }: { detail?: Detail }) {
  return (
    <>
      <label>
        Publication date (if stated)
        <input
          type="date"
          name="publicationDate"
          defaultValue={detail?.publication_date ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
      <label>
        Effective date (if stated)
        <input
          type="date"
          name="effectiveDate"
          defaultValue={detail?.effective_date ?? ""}
          className="block w-full rounded border p-2"
        />
      </label>
    </>
  );
}
export function DocumentUpload({ detail }: { detail?: Detail }) {
  const [busy, setBusy] = useState(false),
    [message, setMessage] = useState("");
  const [target, setTarget] = useState("");
  const router = useRouter();
  return (
    <form
      className="grid gap-3 rounded border border-border p-4 text-sm sm:grid-cols-2"
      onSubmit={async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        setBusy(true);
        setMessage("");
        setTarget("");
        try {
          const result = await change(new FormData(form));
          setTarget(result.versionId);
          setMessage(
            result.duplicate
              ? "Exact duplicate: the existing original and evidence were reused."
              : "Upload queued. Processing continues after you close this page.",
          );
          form.reset();
          router.refresh();
        } catch (error) {
          setMessage(error instanceof Error ? error.message : "Upload failed");
        } finally {
          setBusy(false);
        }
      }}
    >
      <h3 className="font-semibold sm:col-span-2">
        {detail ? "Upload a new version" : "Upload a PDF"}
      </h3>
      <p className="text-muted sm:col-span-2">
        PDF only, up to 25 MiB and 300 physical pages. Local processing may take up to 10 minutes
        per attempt. OCR can be inaccurate. Publication and effective dates are optional.
      </p>
      {detail ? (
        <>
          <input type="hidden" name="documentId" value={detail.document_id} />
          <input type="hidden" name="title" value={detail.title} />
        </>
      ) : (
        <Fields />
      )}
      <Dates />
      {detail && (
        <label className="sm:col-span-2">
          <input type="checkbox" name="supersedes" value={detail.id} /> Record that this version
          supersedes the selected version. This is metadata you confirm, not a legal conclusion.
        </label>
      )}
      <label className="sm:col-span-2">
        PDF file
        <input
          type="file"
          name="file"
          accept="application/pdf,.pdf"
          required
          className="block w-full"
        />
      </label>
      <button
        disabled={busy}
        className="rounded bg-accent px-4 py-2 text-white disabled:opacity-50"
      >
        {busy ? "Uploading…" : "Upload"}
      </button>
      <p role="status" className="sm:col-span-2">
        {message}{" "}
        {target && (
          <Link className="underline" href={`/admin/documents/${target}`}>
            Open document
          </Link>
        )}
      </p>
    </form>
  );
}
export function DocumentCatalog() {
  const [data, setData] = useState<Catalog | null>(null),
    [error, setError] = useState("");
  const [query, setQuery] = useState(""),
    [offset, setOffset] = useState(0);
  const refresh = useCallback(async () => {
    const response = await fetch(withBasePath(`/api/admin/documents?${query}&offset=${offset}`), {
      cache: "no-store",
    });
    if (!response.ok) {
      if (response.status === 403) setData(null);
      throw new Error("The document library is unavailable or access was revoked.");
    }
    setData(await response.json());
    setError("");
  }, [query, offset]);
  useEffect(() => {
    let active = true;
    const load = () =>
      void refresh().catch((e) => {
        if (active) setError(e.message);
      });
    load();
    const timer = setInterval(load, 3000);
    return () => {
      active = false;
      clearInterval(timer);
    };
  }, [refresh]);
  return (
    <section className="flex flex-col gap-4">
      <form
        className="grid gap-3 text-sm sm:grid-cols-3"
        onSubmit={(event) => {
          event.preventDefault();
          const values = new FormData(event.currentTarget);
          setQuery(
            new URLSearchParams([...values.entries()].map(([k, v]) => [k, String(v)])).toString(),
          );
          setOffset(0);
        }}
      >
        <label>
          Search title, agency, number or tags
          <input name="q" className="block w-full rounded border p-2" />
        </label>
        <label>
          Issuing agency
          <input name="issuer" className="block w-full rounded border p-2" />
        </label>
        <label>
          Document type
          <input name="type" className="block w-full rounded border p-2" />
        </label>
        <label>
          Tag
          <input name="tag" className="block w-full rounded border p-2" />
        </label>
        <label>
          Publication date from
          <input name="from" type="date" className="block w-full rounded border p-2" />
        </label>
        <label>
          Publication date to
          <input name="to" type="date" className="block w-full rounded border p-2" />
        </label>
        <label>
          Processing state
          <select name="stage" className="block w-full rounded border p-2">
            <option value="">All states</option>
            {[
              "queued",
              "extracting",
              "ocr",
              "indexing",
              "ready",
              "ready_with_warnings",
              "failed",
            ].map((s) => (
              <option key={s}>{s}</option>
            ))}
          </select>
        </label>
        <label>
          Archived versions
          <select name="archived" className="block w-full rounded border p-2">
            <option value="">Include archived</option>
            <option value="false">Hide archived</option>
          </select>
        </label>
        <button className="rounded border p-2">Apply filters</button>
      </form>
      {error && <p role="alert">{error}</p>}
      {!data && !error && <p>Loading documents…</p>}
      {data?.versions.length === 0 && <p>No documents match these filters.</p>}
      <ul className="grid gap-3">
        {data?.versions.map((v) => (
          <li key={v.id} className="rounded border border-border p-3">
            <Link href={`/admin/documents/${v.id}`} className="font-semibold underline">
              {v.title}
            </Link>
            <p className="text-sm">
              {v.issuer ?? "Agency not stated"} · {v.document_type ?? "Type not stated"} ·{" "}
              {v.archived ? "Archived" : v.availability}
            </p>
            <p className="text-sm">
              Processing: {v.stage} {v.progress ?? 0}% · Embeddings: {v.embedding_status}
            </p>
            {v.safe_error && <p role="status">{v.safe_error}</p>}
            <p className="text-xs text-muted">
              Uploaded {new Date(v.uploaded_at).toLocaleString()} · Publication{" "}
              {v.publication_date ?? "not stated"}
            </p>
          </li>
        ))}
      </ul>
      <div className="flex gap-3">
        <button
          disabled={!offset}
          onClick={() => setOffset((n) => Math.max(0, n - 50))}
          className="rounded border p-2 disabled:opacity-50"
        >
          Previous
        </button>
        <button
          disabled={!data || data.versions.length < 50}
          onClick={() => setOffset((n) => n + 50)}
          className="rounded border p-2 disabled:opacity-50"
        >
          Next
        </button>
      </div>
      <details>
        <summary className="font-semibold">
          Recent upload attempts, including duplicates and failures
        </summary>
        <ul className="mt-3 grid gap-2">
          {data?.attempts.map((a) => (
            <li key={a.id} className="rounded border p-3 text-sm">
              <span className="break-all">{a.original_filename}</span> · {a.outcome}{" "}
              {a.safe_reason && <p>{a.safe_reason}</p>}
              {(a.duplicate_version_id || a.version_id) && (
                <Link
                  className="underline"
                  href={`/admin/documents/${a.duplicate_version_id ?? a.version_id}`}
                >
                  Open associated version
                </Link>
              )}
            </li>
          ))}
        </ul>
      </details>
    </section>
  );
}
export function DocumentControls({ detail }: { detail: Detail }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false),
    [message, setMessage] = useState("");
  useEffect(() => {
    if (
      !detail.jobs.some(
        (j) =>
          ["queued", "extracting", "ocr", "indexing"].includes(j.stage) && j.kind === "extract",
      )
    )
      return;
    const timer = setInterval(() => router.refresh(), 3000);
    return () => clearInterval(timer);
  }, [detail.jobs, router]);
  async function action(body: Record<string, unknown>) {
    setBusy(true);
    setMessage("");
    try {
      await change({ version: detail.id, ...body });
      setMessage("Saved.");
      router.refresh();
    } catch (e) {
      setMessage(e instanceof Error ? e.message : "Change failed");
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap gap-3">
        <button
          disabled={busy}
          className="rounded border p-2"
          onClick={() => void action({ operation: "archive", archived: !detail.archived })}
        >
          {detail.archived ? "Unarchive" : "Archive"}
        </button>
        <button
          disabled={busy}
          className="rounded border p-2"
          onClick={() => void action({ operation: "retry" })}
        >
          Retry / reprocess
        </button>
      </div>
      <p role="status">{message}</p>
      <details>
        <summary>Edit shared document metadata and this version&apos;s dates</summary>
        <form
          className="mt-3 grid gap-3 text-sm sm:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(event.currentTarget));
            const metadata = {
              ...data,
              tags: String(data.tags ?? "")
                .split(",")
                .map((s) => s.trim())
                .filter(Boolean),
            };
            void action({ operation: "metadata", metadata });
          }}
        >
          <Fields detail={detail} />
          <Dates detail={detail} />
          <label>
            Superseded version ID (optional)
            <input
              name="supersedes"
              defaultValue={detail.supersedes_id ?? ""}
              className="block w-full rounded border p-2"
            />
          </label>
          <button disabled={busy} className="rounded border p-2">
            Save metadata
          </button>
        </form>
      </details>
      <details>
        <summary>Upload a new version of this document</summary>
        <DocumentUpload detail={detail} />
      </details>
    </div>
  );
}
