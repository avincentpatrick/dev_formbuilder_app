import { AssistantChat } from "@/components/admin/assistant-chat";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { policyCall } from "@/lib/policy-analysis/evidence";
import { policySourcesSchema, type PolicyRecord } from "@/lib/policy-analysis/schema";
import { listRegisteredDatasets } from "@/lib/db/dataset-registry";
import { z } from "zod";
import { ProjectWorkspace } from "@/components/analysis/project-workspace";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { getGeoByCode } from "@/lib/db/geo";
import { isDatasetScopeId } from "@/lib/ai/scope-id";
import { meridianUrl } from "@/lib/meridian-url";

export const dynamic = "force-dynamic";

export default async function AskTheDataPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved") {
    const signIn = meridianUrl("/login");
    return (
      <section className="mx-auto flex max-w-xl flex-col gap-4 px-4 py-12">
        <h1 className="text-xl font-semibold">Ask the Data</h1>
        {auth.status === "signed_out" ? (
          <>
            <p>
              Sign in with your Meridian account, then return to this page. Ask the Data is
              available only to approved dashboard users.
            </p>
            {signIn && (
              <a href={signIn} className="underline">
                Sign in
              </a>
            )}
          </>
        ) : (
          <>
            <p>
              Dashboard analysis approval is required. Contact a dashboard administrator to request
              access.
            </p>
            {auth.user && (
              <p className="break-all text-sm">
                Your account ID: <code>{auth.user.id}</code>
              </p>
            )}
          </>
        )}
      </section>
    );
  }
  const params = await searchParams;
  if (params.policies || params.libraryVersion || params.libraryCollection) {
    const admin = await getStrictAdminUser();
    if (!admin) return <p className="p-6">Admin role required for policy analysis.</p>;
    const version =
      typeof params.libraryVersion === "string"
        ? params.libraryVersion
        : typeof params.version === "string"
          ? params.version
          : undefined;
    const revision = typeof params.revision === "string" ? params.revision : undefined;
    const collection = typeof params.libraryCollection === "string" ? params.libraryCollection : "";
    if (
      (version && !z.uuid().safeParse(version).success) ||
      (revision && !z.uuid().safeParse(revision).success)
    )
      return <p className="p-6">Invalid policy selection.</p>;
    const initialResult = policySourcesSchema.safeParse({
      mode: version || revision ? "selected" : "active",
      versions: version ? [version] : [],
      revisions: revision ? [revision] : [],
      collection,
      historical: params.historical === "1",
    });
    if (!initialResult.success) return <p className="p-6">Invalid policy selection.</p>;
    const initial = initialResult.data;
    let policies: PolicyRecord[];
    let datasets: Awaited<ReturnType<typeof listRegisteredDatasets>>;
    try {
      [policies, datasets] = await Promise.all([
        policyCall<PolicyRecord[]>(admin.id, "catalog", { historical: true }),
        listRegisteredDatasets("internal"),
      ]);
    } catch {
      return (
        <p className="p-6">
          Policy sources are unavailable. Try again later; no online research was enabled.
        </p>
      );
    }
    return (
      <section className="mx-auto grid max-w-4xl gap-4 p-4">
        <h1 className="text-xl font-semibold">Ask the Data · policies and datasets</h1>
        <p>
          Compare policy evidence with applicable registered data. Sources are checked again for
          each question.
        </p>
        <AssistantChat
          key={JSON.stringify(initial)}
          canReport
          initialPolicySources={initial}
          policyOptions={{
            policies: policies.map((p) => ({ ...p, warnings: [] })),
            datasets: datasets.map((d) => ({
              tableName: d.tableName,
              title: d.title,
              grain: d.grain,
            })),
          }}
        />
      </section>
    );
  }

  const dataset = isDatasetScopeId(params.dataset) ? params.dataset : undefined;
  const geo =
    typeof params.geoCode === "string" && params.geoCode.length <= 20
      ? await getGeoByCode(params.geoCode).catch(() => null)
      : null;
  return (
    <section className="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-8">
      <h1 className="text-xl font-semibold">Ask the Data</h1>
      <p className="text-sm text-muted">
        Explore dashboard data, document sources and their relationships. Answers include source
        evidence and checked figures. Conversations are saved as private projects. Relevant
        attachments and online evidence can be included. Source availability and preparation notes
        are shown in the project.
      </p>
      {dataset && <p className="text-sm">Dashboard context: {dataset}</p>}
      {geo && <p className="text-sm">Starting place: {geo.geoName}</p>}
      <p className="break-all text-xs text-muted">Your sharing ID: {auth.user.id}</p>
      <ProjectWorkspace
        key={typeof params.projectId === "string" ? params.projectId : "new"}
        projectId={typeof params.projectId === "string" ? params.projectId : undefined}
        context={{
          dataset,
          carriedScope: geo
            ? { geoCode: geo.geoCode, geoLevel: geo.geoLevel, geoName: geo.geoName }
            : null,
        }}
      />
    </section>
  );
}
