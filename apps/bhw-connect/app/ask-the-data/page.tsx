import { AssistantChat } from "@/components/admin/assistant-chat";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { getAdminUser } from "@/lib/db/require-admin";
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
  const dataset = isDatasetScopeId(params.dataset) ? params.dataset : undefined;
  const geo =
    typeof params.geoCode === "string" && params.geoCode.length <= 20
      ? await getGeoByCode(params.geoCode).catch(() => null)
      : null;
  const admin = await getAdminUser();
  return (
    <section className="mx-auto flex w-full max-w-5xl flex-col gap-4 px-4 py-8">
      <h1 className="text-xl font-semibold">Ask the Data</h1>
      <p className="text-sm text-muted">
        Explore dashboard data, document sources and their relationships. Answers include source
        evidence and checked figures. Online research, attachments and saved projects will be added
        in later phases.
      </p>
      {dataset && <p className="text-sm">Dashboard context: {dataset}</p>}
      {geo && <p className="text-sm">Starting place: {geo.geoName}</p>}
      <AssistantChat
        dataset={dataset}
        initialScope={
          geo ? { geoCode: geo.geoCode, geoLevel: geo.geoLevel, geoName: geo.geoName } : null
        }
        canReport={admin !== null}
      />
    </section>
  );
}
