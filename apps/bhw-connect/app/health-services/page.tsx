import type { Metadata } from "next";
import { AreaDashboard } from "@/components/health-services/area-dashboard";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: { absolute: "Health services · FHSIS 2025" } };
export default function HealthServices() {
  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-3xl font-semibold tracking-tight">
        What health services are being delivered?
      </h1>
      <p className="max-w-2xl text-muted">
        The DOH Field Health Services Information System reports immunisation, maternal care, water
        and sanitation, and TB services. Explore the 2025 partial release from the Philippines down
        to cities and municipalities.
      </p>
      <AreaDashboard
        geo={{
          geoCode: NATIONAL_GEO_CODE,
          geoLevel: "national",
          geoName: "Philippines",
          incomeClass: null,
        }}
      />
    </div>
  );
}
