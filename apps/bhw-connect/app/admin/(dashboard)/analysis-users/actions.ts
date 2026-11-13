"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { z } from "zod";
import { getAdminUser } from "@/lib/db/require-admin";
import { createSupabaseServiceClient } from "@/lib/db/service-client";

export async function setAnalysisAccess(form: FormData) {
  const admin = await getAdminUser();
  if (admin?.role !== "admin") throw new Error("Administrator access required.");
  const userId = z.uuid().safeParse(form.get("userId"));
  const decision = form.get("decision");
  if (!userId.success || (decision !== "approve" && decision !== "revoke")) {
    redirect("/admin/analysis-users?result=invalid");
  }
  const { error } = await createSupabaseServiceClient()
    .from("analysis_users")
    .upsert({
      user_id: userId.data,
      approved: decision === "approve",
      updated_by: admin.id,
      updated_at: new Date().toISOString(),
    });
  if (error) redirect("/admin/analysis-users?result=failed");
  revalidatePath("/admin/analysis-users");
  revalidatePath("/ask-the-data");
  redirect("/admin/analysis-users?result=saved");
}
