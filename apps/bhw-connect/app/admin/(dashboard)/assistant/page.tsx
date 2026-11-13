import { getStrictAdminUser } from "@/lib/db/require-admin";
import { notFound, redirect } from "next/navigation";

export default async function AdminAssistantPage() {
  if (!(await getStrictAdminUser())) notFound();
  redirect("/ask-the-data");
}
