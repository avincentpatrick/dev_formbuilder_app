import { getAdminUser } from "@/lib/db/require-admin";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
import { setAnalysisAccess } from "./actions";

export default async function AnalysisUsersPage({
  searchParams,
}: {
  searchParams: Promise<{ result?: string }>;
}) {
  if ((await getAdminUser())?.role !== "admin") return <p>Administrator access required.</p>;
  const { result } = await searchParams;
  const { data, error } = await createSupabaseServiceClient()
    .from("analysis_users")
    .select("user_id,approved,updated_at")
    .order("updated_at", { ascending: false });
  return (
    <section className="flex flex-col gap-4">
      <h2 className="text-lg font-semibold">Ask the Data access</h2>
      <p className="text-sm">
        Approve existing Meridian accounts using the account ID shown on their Ask the Data access
        page. Approval grants analysis access, including the shared dashboard catalogue, document
        sources and lineage. It does not grant administrator privileges. Administrators also need
        explicit analysis approval.
      </p>
      {result && (
        <p role="status">
          {result === "saved"
            ? "Access updated."
            : "Access was not updated. Check the account ID and try again."}
        </p>
      )}
      {error && <p role="alert">Approval records could not be loaded.</p>}
      <form action={setAnalysisAccess} className="flex flex-col gap-2">
        <label htmlFor="userId">Meridian account ID</label>
        <input
          id="userId"
          name="userId"
          required
          className="rounded border border-border bg-background p-2"
        />
        <div className="flex gap-3">
          <button
            name="decision"
            value="approve"
            className="rounded bg-accent px-3 py-2 text-accent-foreground"
          >
            Approve
          </button>
          <button name="decision" value="revoke" className="rounded border border-border px-3 py-2">
            Revoke
          </button>
        </div>
      </form>
      <ul className="flex flex-col gap-3">
        {data?.map((row) => (
          <li key={row.user_id} className="break-all rounded border border-border p-3 text-sm">
            <p>{row.user_id}</p>
            <p>
              {row.approved ? "Approved" : "Revoked"} · {row.updated_at}
            </p>
            <form action={setAnalysisAccess}>
              <input type="hidden" name="userId" value={row.user_id} />
              <button
                name="decision"
                value={row.approved ? "revoke" : "approve"}
                className="underline"
              >
                {row.approved ? "Revoke access" : "Approve access"}
              </button>
            </form>
          </li>
        ))}
      </ul>
    </section>
  );
}
