import AskTheDataPage from "../../page";
export const dynamic = "force-dynamic";
export default async function ProjectPage({ params }: { params: Promise<{ projectId: string }> }) {
  return <AskTheDataPage searchParams={params} />;
}
