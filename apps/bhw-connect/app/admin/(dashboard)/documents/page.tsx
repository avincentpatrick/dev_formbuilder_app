import Link from "next/link";
import { notFound } from "next/navigation";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { DocumentUpload, DocumentCatalog } from "@/components/admin/document-library";
export default async function DocumentsPage() {
  if (!(await getStrictAdminUser())) notFound();
  return (
    <div className="flex flex-col gap-6">
      <h2 className="text-xl font-semibold">Document library</h2>
      <p className="text-sm">
        Shared by BHW administrators. Usable text becomes searchable after local processing, without
        an approval queue.
      </p>
      <Link href="/ask-the-data?policies=active" className="underline">
        Analyze policies with dataset evidence
      </Link>
      <DocumentUpload />
      <DocumentCatalog />
    </div>
  );
}
