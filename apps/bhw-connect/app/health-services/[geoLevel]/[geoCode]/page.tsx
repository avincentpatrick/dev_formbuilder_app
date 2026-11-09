import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";
import { cache } from "react";
import { AreaDashboard } from "@/components/health-services/area-dashboard";
import { Breadcrumbs } from "@/components/layout/breadcrumbs";
import { PORTAL_CRUMB, HEALTH_SERVICES_CRUMB } from "@/lib/nav/breadcrumbs";
import { getGeoAncestors, getGeoByCode } from "@/lib/db/geo";
import { fhsisAreaHref, isFhsisLevel, FHSIS_RELEASE_LABEL } from "@/lib/db/fhsis";
export const dynamic = "force-dynamic";
type Params = { geoLevel: string; geoCode: string };
const loadGeo = cache(async (level: string, code: string) => {
  if (!isFhsisLevel(level)) return null;
  const geo = await getGeoByCode(code);
  return geo?.geoLevel === level ? geo : null;
});
export async function generateMetadata({ params }: { params: Promise<Params> }): Promise<Metadata> {
  const { geoLevel, geoCode } = await params;
  const geo = await loadGeo(geoLevel, geoCode);
  return { title: geo?.geoName ?? "Area not found" };
}
export default async function HealthServicesArea({ params }: { params: Promise<Params> }) {
  const { geoLevel, geoCode } = await params;
  if (geoLevel === "barangay") {
    const barangay = await getGeoByCode(geoCode);
    if (!barangay || barangay.geoLevel !== "barangay") notFound();
    const ancestors = await getGeoAncestors(geoCode, "barangay");
    if (!ancestors.citymun) notFound();
    redirect(`/health-services/citymun/${ancestors.citymun.geoCode}`);
  }
  const geo = await loadGeo(geoLevel, geoCode);
  if (!geo) notFound();
  const ancestors = await getGeoAncestors(geo.geoCode, geo.geoLevel);
  const parents = [ancestors.region, ancestors.province].filter(
    (g) => g && g.geoCode !== geo.geoCode,
  );
  return (
    <div className="flex flex-col gap-6">
      <Breadcrumbs
        items={[
          PORTAL_CRUMB,
          HEALTH_SERVICES_CRUMB,
          ...parents.map((g) => ({
            label: g!.geoName,
            href: fhsisAreaHref(g!.geoLevel, g!.geoCode),
          })),
          { label: geo.geoName },
        ]}
      />
      <h1 className="text-3xl font-semibold tracking-tight">Health services in {geo.geoName}</h1>
      <p className="text-muted">
        {FHSIS_RELEASE_LABEL} · {geo.geoLevel === "citymun" ? "City / municipality" : geo.geoLevel}
      </p>
      <AreaDashboard geo={geo} />
    </div>
  );
}
